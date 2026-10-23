<?php

/*
 * Apply for student leave.
 *     $dispatch('open-modal', { component: 'forms.student-leave', params: { as: 'parent' } })   parent portal: own children
 *     $dispatch('open-modal', { component: 'forms.student-leave', params: { as: 'school' } })   teacher portal: record for a student
 *
 * Recorded by the school, the request can be approved straight away.
 */

use App\Enums\LeaveAppliesTo;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Leave\StudentLeave;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;

use function Livewire\Volt\{computed, mount, protect, rules, state, uses};

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['as' => 'parent'])->locked();

state(['student_id' => '', 'leave_type_id' => '', 'start_date' => '', 'end_date' => '', 'reason' => '', 'attachment' => null, 'approve_now' => true, 'studentSearch' => '', 'studentLabel' => '']);

// Students this form may apply for.
$eligible = protect(function () {
    $query = StudentProfile::query()->active();
    $user = auth()->user();

    if ($this->as === 'parent') {
        return $query->whereIn('id', $user->parentProfile()->first()?->children()->select('student_profiles.id') ?? [0]);
    }

    if (! $user->can('attendance.manage')) {
        $staffId = $user->staffProfile()->value('id');
        $query->whereIn('section_id', Section::query()->where('class_teacher_id', $staffId ?: 0)->select('id'));
    }

    return $query;
});

mount(function (string $as = 'parent') {
    abort_unless(in_array($as, ['parent', 'school'], true), 404);
    $this->as = $as;

    if ($as === 'parent') {
        abort_unless(auth()->user()->canAccessPortal('parent') && auth()->user()->parentProfile()->exists(), 403);
        $this->student_id = (string) ($this->children->keys()->first() ?? '');
    } else {
        $this->authorize('student-leave.approve');
    }

    $this->start_date = tenant()->today()->toDateString();
    $this->end_date = $this->start_date;
    $this->leave_type_id = (string) ($this->types->keys()->first() ?? '');
});

$children = computed(fn () => $this->as === 'parent'
    ? $this->eligible()->with('user')->get()->mapWithKeys(fn ($child) => [$child->id => $child->user?->name])
    : collect());

$types = computed(fn () => LeaveType::query()->availableTo(LeaveAppliesTo::Student)->pluck('name', 'id'));

$selectedType = computed(fn () => $this->leave_type_id ? LeaveType::query()->find($this->leave_type_id) : null);

$studentResults = computed(function () {
    $term = trim($this->studentSearch);

    if ($this->as !== 'school' || mb_strlen($term) < 2) {
        return collect();
    }

    $like = '%'.addcslashes($term, '%_\\').'%';

    return $this->eligible()->with(['user', 'schoolClass', 'section'])
        ->where(fn ($query) => $query->where('admission_number', 'like', $like)->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like)))
        ->limit(8)
        ->get();
});

$days = computed(function () {
    try {
        $start = Carbon::parse($this->start_date);
        $end = Carbon::parse($this->end_date);
    } catch (Throwable) {
        return null;
    }

    return $end->lt($start) ? null : app(SchoolCalendar::class)->countSchoolDays($start, $end);
});

$pickStudent = function (int $id) {
    $student = $this->eligible()->with(['user', 'schoolClass', 'section'])->findOrFail($id);

    $this->student_id = (string) $student->id;
    $this->studentLabel = $student->user?->name.($student->placementLabel() ? ' · '.$student->placementLabel() : '');
    $this->studentSearch = '';
};

rules(fn () => [
    'student_id' => ['required', 'integer'],
    'leave_type_id' => ['required', Rule::in($this->types->keys()->map(fn ($id) => (string) $id)->all())],
    'start_date' => ['required', 'date'],
    'end_date' => ['required', 'date', 'after_or_equal:start_date'],
    'reason' => ['required', 'string', 'max:1000'],
    'attachment' => [$this->selectedType?->requires_attachment ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
    'approve_now' => ['boolean'],
])->attributes([
    'student_id' => __('student'),
    'leave_type_id' => __('leave type'),
    'start_date' => __('first day'),
    'end_date' => __('last day'),
]);

$save = function (StudentLeave $leave) {
    $data = $this->validate();

    $student = $this->eligible()->with('user')->findOrFail($data['student_id']);
    $path = null;

    try {
        DB::transaction(function () use ($leave, $student, $data, &$path) {
            if ($this->attachment) {
                $path = $this->attachment->storeAs('schools/'.tenant()->id.'/leave', Str::lower(Str::random(32)).'.'.strtolower($this->attachment->getClientOriginalExtension()), LeaveRequest::DISK);
            }

            $request = $leave->apply($student, LeaveType::query()->findOrFail($data['leave_type_id']), Carbon::parse($data['start_date']), Carbon::parse($data['end_date']), $data['reason'], auth()->user(), $path);

            if ($this->as === 'school' && $data['approve_now']) {
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
    $this->toast($this->as === 'school' && $data['approve_now'] ? __('Leave recorded and approved.') : __('Leave request sent.'));
};

?>

<x-modal.form :title="$as === 'parent' ? __('Apply for leave') : __('Record student leave')" :submit-label="$as === 'parent' ? __('Send request') : __('Save')">
    @if ($as === 'parent')
        @if ($this->children->count() > 1)
            <x-form.select name="student_id" :label="__('Child')" :options="$this->children->all()" :placeholder="false" required />
        @else
            <p class="mb-4"><span class="text-muted">{{ __('For') }}:</span> <span class="fw-medium">{{ $this->children->first() }}</span></p>
        @endif
    @else
        <div class="mb-4 position-relative">
            <label class="form-label" for="leave-student">{{ __('Student') }}<span class="text-danger ms-1">*</span></label>
            @if ($student_id && $studentSearch === '')
                <div class="input-group">
                    <span class="form-control bg-light">{{ $studentLabel }}</span>
                    <button type="button" class="btn btn-light" wire:click="$set('student_id', '')">{{ __('Change') }}</button>
                </div>
            @else
                <input type="search" id="leave-student" autocomplete="off" wire:model.live.debounce.300ms="studentSearch"
                       @class(['form-control', 'is-invalid' => $errors->has('student_id')]) placeholder="{{ __('Search by name or admission number…') }}">
                @if (mb_strlen(trim($studentSearch)) >= 2)
                    <div class="list-group position-absolute start-0 end-0 shadow-sm z-3 mt-1">
                        @forelse ($this->studentResults as $result)
                            <button type="button" class="list-group-item list-group-item-action" wire:click="pickStudent({{ $result->id }})">
                                <span class="fw-medium">{{ $result->user?->name }}</span>
                                <small class="text-muted ms-1">{{ $result->placementLabel() }}</small>
                            </button>
                        @empty
                            <div class="list-group-item text-muted fs-13">{{ __('No student matches in your sections.') }}</div>
                        @endforelse
                    </div>
                @endif
                @error('student_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            @endif
        </div>
    @endif

    <x-form.select name="leave_type_id" :label="__('Leave type')" :options="$this->types->all()" :placeholder="false" required live />

    <div class="row">
        <div class="col-sm-6"><x-form.input name="start_date" type="date" :label="__('First day')" required live /></div>
        <div class="col-sm-6"><x-form.input name="end_date" type="date" :label="__('Last day')" required live /></div>
    </div>
    @if ($this->days !== null)
        <p class="text-muted fs-13 mt-n2 mb-4">{{ trans_choice('{0} No school days in these dates.|{1} :count school day|[2,*] :count school days', $this->days, ['count' => $this->days]) }}</p>
    @endif

    <x-form.textarea name="reason" :label="__('Reason')" rows="3" maxlength="1000" required />

    <div class="mb-3">
        <label class="form-label" for="leave-attachment">{{ __('Attachment') }}@if ($this->selectedType?->requires_attachment)<span class="text-danger ms-1">*</span>@endif</label>
        <input type="file" id="leave-attachment" wire:model="attachment" accept=".pdf,image/png,image/jpeg"
               @class(['form-control', 'is-invalid' => $errors->has('attachment')])>
        @error('attachment') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">{{ __('e.g. a medical certificate. PDF, JPG or PNG up to 4 MB.') }}</div>
    </div>

    @if ($as === 'school')
        <x-form.switch name="approve_now" :label="__('Approve now')" :hint="__('Turn off to leave it pending for the class teacher.')" wrapper="mb-0" />
    @endif
</x-modal.form>
