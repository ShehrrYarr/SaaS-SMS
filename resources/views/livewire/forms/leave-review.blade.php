<?php

/*
 * Approve or reject a student leave request, with optional remarks.
 *     $dispatch('open-modal', { component: 'forms.leave-review', params: { request: 5, decision: 'approve' } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\LeaveRequest;
use App\Models\StudentProfile;
use App\Services\Leave\StudentLeave;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Relations\MorphTo;

use function Livewire\Volt\{computed, mount, protect, state, uses};

uses([InteractsWithUi::class]);

state(['request' => null, 'decision' => 'approve'])->locked();

state(['remarks' => '']);

$record = protect(fn (): LeaveRequest => LeaveRequest::query()
    ->with(['leaveType', 'requestedBy', 'applicant' => fn (MorphTo $morph) => $morph->morphWith([StudentProfile::class => ['user', 'schoolClass', 'section']])])
    ->where('applicant_type', (new StudentProfile)->getMorphClass())
    ->findOrFail($this->request));

mount(function (int $request, string $decision) {
    abort_unless(in_array($decision, ['approve', 'reject'], true), 404);

    $this->request = $request;
    $this->decision = $decision;

    abort_unless(app(StudentLeave::class)->canReview(auth()->user(), $this->record()), 403);
});

$details = computed(fn () => $this->record());

$save = function (StudentLeave $leave) {
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
@endphp

<x-modal.form :title="$approve ? __('Approve leave') : __('Reject leave')" :subtitle="$request->applicant?->user?->name"
              :submit-label="$approve ? __('Approve') : __('Reject')" :submit-color="$approve ? 'success' : 'danger'">
    <dl class="detail-list mb-4">
        <dt>{{ __('Class') }}</dt><dd>{{ $request->applicant?->placementLabel() ?? '—' }}</dd>
        <dt>{{ __('Dates') }}</dt>
        <dd>{{ $request->start_date->format($format) }} – {{ $request->end_date->format($format) }}
            <small class="text-muted">({{ trans_choice(':count school day|:count school days', (int) ceil((float) $request->days), ['count' => (float) $request->days]) }})</small></dd>
        <dt>{{ __('Type') }}</dt><dd>{{ $request->leaveType?->name }}</dd>
        <dt>{{ __('Reason') }}</dt><dd class="text-pre-line">{{ $request->reason }}</dd>
        <dt>{{ __('Requested by') }}</dt><dd>{{ $request->requestedBy?->name ?? '—' }}</dd>
        @if ($request->attachment)
            <dt>{{ __('Attachment') }}</dt>
            <dd><a href="{{ route('tenant.leave.attachment', $request->id) }}" target="_blank" rel="noopener"><i class="ri-attachment-2 me-1"></i>{{ __('Open') }}</a></dd>
        @endif
    </dl>

    <x-form.input name="remarks" :label="__('Remarks')" maxlength="255" wrapper="mb-3"
                  :placeholder="$approve ? __('Optional') : __('e.g. Please provide a medical certificate')" />

    @if ($approve)
        <p class="text-muted fs-13 mb-0">{{ __('Days already marked absent in this period become excused, and the register shows the student as on leave.') }}</p>
    @endif
</x-modal.form>
