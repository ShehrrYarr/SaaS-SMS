<?php

/*
 * Approve or reject a staff leave request, with optional remarks.
 *     $dispatch('open-modal', { component: 'forms.staff-leave-review', params: { request: 5, decision: 'approve' } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\LeaveRequest;
use App\Models\StaffProfile;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Leave\LeaveBalances;
use App\Services\Leave\StaffLeave;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Relations\MorphTo;

use function Livewire\Volt\{computed, mount, protect, state, uses};

uses([InteractsWithUi::class]);

state(['request' => null, 'decision' => 'approve'])->locked();

state(['remarks' => '']);

$record = protect(fn (): LeaveRequest => LeaveRequest::query()
    ->with(['leaveType', 'requestedBy', 'applicant' => fn (MorphTo $morph) => $morph->morphWith([StaffProfile::class => ['user', 'department']])])
    ->where('applicant_type', (new StaffProfile)->getMorphClass())
    ->findOrFail($this->request));

mount(function (int $request, string $decision) {
    abort_unless(in_array($decision, ['approve', 'reject'], true), 404);

    $this->request = $request;
    $this->decision = $decision;

    abort_unless(app(StaffLeave::class)->canReview(auth()->user(), $this->record()), 403);
});

$details = computed(fn () => $this->record());

// Days of this type left for the year, not counting this request; null = no limit.
$available = computed(function () {
    $request = $this->details;

    return app(LeaveBalances::class)->available($request->applicant, $request->leaveType, app(SchoolCalendar::class)->yearFor($request->start_date), $request->id);
});

$save = function (StaffLeave $leave) {
    $this->validate(['remarks' => ['nullable', 'string', 'max:255']]);

    $request = $this->record();
    abort_unless($leave->canReview(auth()->user(), $request), 403);

    try {
        $this->decision === 'approve'
            ? $leave->approve($request, auth()->user(), $this->remarks ?: null)
            : $leave->reject($request, auth()->user(), $this->remarks ?: null);
    } catch (ActionFailed $e) {
        $this->addError('remarks', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->decision === 'approve' ? __('Leave approved.') : __('Leave rejected.'));
};

?>

@php
    $request = $this->details;
    $format = tenant()->setting('date_format');
    $approve = $decision === 'approve';
    $trim = fn ($days) => rtrim(rtrim(number_format((float) $days, 1, '.', ''), '0'), '.');
@endphp

<x-modal.form :title="$approve ? __('Approve leave') : __('Reject leave')" :subtitle="$request->applicant?->user?->name"
              :submit-label="$approve ? __('Approve') : __('Reject')" :submit-color="$approve ? 'success' : 'danger'">
    <dl class="detail-list mb-4">
        <dt>{{ __('Department') }}</dt><dd>{{ $request->applicant?->department?->name ?? '—' }}</dd>
        <dt>{{ __('Dates') }}</dt>
        <dd>{{ $request->start_date->format($format) }}@if ($request->end_date->ne($request->start_date)) – {{ $request->end_date->format($format) }}@endif
            <small class="text-muted">({{ (float) $request->days === 0.5 ? __('half day') : trans_choice(':count day|:count days', (int) ceil((float) $request->days), ['count' => $trim($request->days)]) }})</small></dd>
        <dt>{{ __('Type') }}</dt>
        <dd>{{ $request->leaveType?->name }}@unless ($request->leaveType?->is_paid) <span class="badge bg-warning-subtle text-warning">{{ __('Unpaid') }}</span>@endunless</dd>
        @if ($this->available !== null)
            <dt>{{ __('Left this year') }}</dt>
            <dd @class(['text-danger' => (float) $request->days > $this->available])>{{ __(':days day(s) before this request', ['days' => $trim(max($this->available, 0))]) }}</dd>
        @endif
        <dt>{{ __('Reason') }}</dt><dd class="text-pre-line">{{ $request->reason }}</dd>
        @if ($request->attachment)
            <dt>{{ __('Attachment') }}</dt>
            <dd><a href="{{ route('tenant.leave.attachment', $request->id) }}" target="_blank" rel="noopener"><i class="ri-attachment-2 me-1"></i>{{ __('Open') }}</a></dd>
        @endif
    </dl>

    <x-form.input name="remarks" :label="__('Remarks')" maxlength="255" wrapper="mb-3" :placeholder="__('Optional')" />

    @if ($approve)
        <p class="text-muted fs-13 mb-0">{{ __('Days already marked absent in this period become leave in the staff register.') }}</p>
    @endif
</x-modal.form>
