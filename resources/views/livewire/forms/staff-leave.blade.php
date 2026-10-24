<?php

/*
 * Apply for staff leave.
 *     $dispatch('open-modal', { component: 'forms.staff-leave', params: { as: 'self' } })   own leave
 *     $dispatch('open-modal', { component: 'forms.staff-leave', params: { as: 'hr' } })     HR records it for someone
 */

use App\Enums\LeaveAppliesTo;
use App\Enums\UserStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StaffProfile;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Leave\LeaveBalances;
use App\Services\Leave\StaffLeave;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\Uploads;
use Livewire\WithFileUploads;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['as' => 'self'])->locked();

state(['staff_id' => '', 'leave_type_id' => '', 'start_date' => '', 'end_date' => '', 'half_day' => false, 'reason' => '', 'attachment' => null, 'approve_now' => true]);

mount(function (string $as = 'self') {
    abort_unless(in_array($as, ['self', 'hr'], true), 404);
    $this->as = $as;

    if ($as === 'self') {
        $this->authorize('leave.apply');
        $this->staff_id = (string) (auth()->user()->staffProfile()->value('id') ?? abort(403));
    } else {
        $this->authorize('leave.approve');
    }

    $this->start_date = tenant()->today()->toDateString();
    $this->end_date = $this->start_date;
    $this->leave_type_id = (string) ($this->types->keys()->first() ?? '');
});

$staffOptions = computed(fn () => $this->as === 'hr'
    ? StaffProfile::query()->with('user')
        ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active)->whereKeyNot(auth()->id()))
        ->get()
        ->sortBy(fn (StaffProfile $staff) => mb_strtolower((string) $staff->user?->name))
        ->mapWithKeys(fn (StaffProfile $staff) => [$staff->id => $staff->user?->name.' ('.$staff->employee_id.')'])
        ->all()
    : []);

$types = computed(fn () => LeaveType::query()->availableTo(LeaveAppliesTo::Staff)->pluck('name', 'id'));

$selectedType = computed(fn () => $this->leave_type_id ? LeaveType::query()->find($this->leave_type_id) : null);

$staff = computed(fn () => $this->staff_id ? StaffProfile::query()->with('user')->find((int) $this->staff_id) : null);

$singleDay = computed(fn () => $this->start_date !== '' && $this->start_date === $this->end_date);

$days = computed(function () {
    try {
        $start = Carbon::parse($this->start_date);
        $end = Carbon::parse($this->end_date);
    } catch (Throwable) {
        return null;
    }

    if ($end->lt($start)) {
        return null;
    }

    $days = app(SchoolCalendar::class)->countSchoolDays($start, $end);

    return $days > 0 && $this->half_day && $this->singleDay ? 0.5 : $days;
});

// Days left of the chosen type this year, or null when it has no limit.
$available = computed(function () {
    if (! $this->staff || ! $this->selectedType) {
        return null;
    }

    $date = rescue(fn () => Carbon::parse($this->start_date), tenant()->today(), false);

    return app(LeaveBalances::class)->available($this->staff, $this->selectedType, app(SchoolCalendar::class)->yearFor($date));
});

rules(fn () => [
    'staff_id' => ['required', $this->as === 'hr' ? Rule::in(array_map('strval', array_keys($this->staffOptions))) : 'integer'],
    'leave_type_id' => ['required', Rule::in($this->types->keys()->map(fn ($id) => (string) $id)->all())],
    'start_date' => ['required', 'date'],
    'end_date' => ['required', 'date', 'after_or_equal:start_date'],
    'half_day' => ['boolean'],
    'reason' => ['required', 'string', 'max:1000'],
    'attachment' => [$this->selectedType?->requires_attachment ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
    'approve_now' => ['boolean'],
])->attributes([
    'staff_id' => __('staff member'),
    'leave_type_id' => __('leave type'),
    'start_date' => __('first day'),
    'end_date' => __('last day'),
]);

$save = function (StaffLeave $leave) {
    $this->authorize($this->as === 'hr' ? 'leave.approve' : 'leave.apply');
    $data = $this->validate();

    $staff = $this->as === 'self'
        ? auth()->user()->staffProfile()->firstOrFail()
        : StaffProfile::query()->findOrFail((int) $data['staff_id']);
    $path = null;

    try {
        DB::transaction(function () use ($leave, $staff, $data, &$path) {
            if ($this->attachment) {
                $path = Uploads::store($this->attachment, 'schools/'.tenant()->id.'/leave', Str::lower(Str::random(32)), ['pdf', 'jpg', 'jpeg', 'png'], LeaveRequest::DISK);
            }

            $request = $leave->apply($staff, LeaveType::query()->findOrFail($data['leave_type_id']), Carbon::parse($data['start_date']), Carbon::parse($data['end_date']),
                $data['reason'], auth()->user(), $path, $data['half_day'] && $data['start_date'] === $data['end_date']);

            if ($this->as === 'hr' && $data['approve_now']) {
                $leave->approve($request, auth()->user());
            }
        });
    } catch (ActionFailed $e) {
        if ($path) {
            Storage::disk(LeaveRequest::DISK)->delete($path);
        }

        $this->addError('start_date', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('leave-changed');
    $this->toast($this->as === 'hr' && $data['approve_now'] ? __('Leave recorded and approved.') : __('Leave request sent.'));
};

?>

<x-modal.form :title="$as === 'self' ? __('Apply for leave') : __('Record staff leave')" :submit-label="$as === 'self' ? __('Send request') : __('Save')">
    @if ($as === 'hr')
        <x-form.select name="staff_id" :label="__('Staff member')" :options="$this->staffOptions" required live />
    @endif

    <x-form.select name="leave_type_id" :label="__('Leave type')" :options="$this->types->all()" :placeholder="false" required live />

    @if ($this->selectedType)
        <p class="text-muted fs-13 mt-n3">
            {{ $this->selectedType->is_paid ? __('Paid leave.') : __('Unpaid leave: deducted from salary.') }}
            @if ($this->available !== null)
                {{ __(':days day(s) left this year.', ['days' => rtrim(rtrim(number_format(max($this->available, 0), 1, '.', ''), '0'), '.')]) }}
            @endif
        </p>
    @endif

    <div class="row">
        <div class="col-sm-6"><x-form.input name="start_date" type="date" :label="__('First day')" required live /></div>
        <div class="col-sm-6"><x-form.input name="end_date" type="date" :label="__('Last day')" required live /></div>
    </div>
    @if ($this->singleDay)
        <x-form.switch name="half_day" :label="__('Half day')" live wrapper="mb-3" />
    @endif
    @if ($this->days !== null)
        <p class="text-muted fs-13 mt-n2 mb-4">{{ $this->days === 0.5 ? __('Half a working day') : trans_choice('{0} No working days in these dates.|{1} :count working day|[2,*] :count working days', $this->days, ['count' => $this->days]) }}</p>
    @endif

    <x-form.textarea name="reason" :label="__('Reason')" rows="3" maxlength="1000" required />

    <div class="mb-3">
        <label class="form-label" for="staff-leave-attachment">{{ __('Attachment') }}@if ($this->selectedType?->requires_attachment)<span class="text-danger ms-1">*</span>@endif</label>
        <input type="file" id="staff-leave-attachment" wire:model="attachment" accept=".pdf,image/png,image/jpeg" @class(['form-control', 'is-invalid' => $errors->has('attachment')])>
        @error('attachment') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">{{ __('e.g. a medical certificate. PDF, JPG or PNG up to 4 MB.') }}</div>
    </div>

    @if ($as === 'hr')
        <x-form.switch name="approve_now" :label="__('Approve now')" wrapper="mb-0" />
    @endif
</x-modal.form>
