<?php

/*
 * Review one student's homework: read what they handed in, then grade it or
 * send it back to redo.
 *     $dispatch('open-modal', { component: 'forms.homework-review', params: { homework: 5, student: 12 }, size: 'lg' })
 *
 * Work done on paper can be graded without a submission.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StudentProfile;
use App\Services\Homework\Homeworks;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, mount, state, uses};

uses([InteractsWithUi::class]);

state(['homework' => null, 'student' => null])->locked();

state(['marks' => '', 'feedback' => '']);

mount(function (int $homework, int $student) {
    $this->authorize('homework.grade');

    $record = Homework::query()->with('subjectClass')->findOrFail($homework);
    abort_unless(app(Homeworks::class)->looksAfter(auth()->user(), $record), 403);

    $this->homework = $record->id;
    $this->student = StudentProfile::query()->findOrFail($student)->id;

    $submission = $this->submission;
    $this->marks = $submission?->marks !== null ? Homeworks::number((float) $submission->marks) : '';
    $this->feedback = (string) ($submission?->feedback ?? '');
});

$record = computed(fn () => Homework::query()->with(['subjectClass.subject'])->findOrFail($this->homework));

$profile = computed(fn () => StudentProfile::query()->with('user')->findOrFail($this->student));

$submission = computed(fn () => HomeworkSubmission::query()->where('homework_id', $this->homework)->where('student_profile_id', $this->student)->first());

$grade = function (Homeworks $homeworks) {
    $this->authorize('homework.grade');

    $max = $this->record->max_marks !== null ? (float) $this->record->max_marks : null;

    $this->validate([
        'marks' => ['nullable', 'numeric', 'min:0', ...($max !== null ? ['max:'.$max] : [])],
        'feedback' => ['nullable', 'string', 'max:2000'],
    ], [], ['marks' => __('marks'), 'feedback' => __('feedback')]);

    try {
        $homeworks->grade($this->record, $this->profile, filled($this->marks) ? (float) $this->marks : null, $this->feedback, auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('marks', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->dispatch('homework-updated');
    $this->toast(__('Graded :name.', ['name' => $this->profile->user?->name]));
};

$sendBack = function (Homeworks $homeworks) {
    $this->authorize('homework.grade');

    $this->validate(['feedback' => ['required', 'string', 'max:2000']], ['feedback.required' => __('Tell the student what to change.')]);

    try {
        throw_unless($this->submission, ActionFailed::class, __('Only work that was handed in can be sent back.'));
        $homeworks->sendBack($this->submission, $this->feedback, auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('feedback', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->dispatch('homework-updated');
    $this->toast(__('Sent back to :name.', ['name' => $this->profile->user?->name]), 'info');
};

?>

@php
    $homework = $this->record;
    $submission = $this->submission;
    $school = tenant();
@endphp

<x-modal.form :title="$this->profile->user?->name" :subtitle="$homework->title" submit="grade" :submit-label="__('Save grade')">
    <div class="border rounded p-3 mb-4">
        @if (! $submission || (! $submission->content && ! $submission->attachment))
            <p class="text-muted mb-0 fs-13"><i class="ri-inbox-line me-1"></i>{{ __('Nothing handed in online. You can still grade work done on paper.') }}</p>
        @else
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="badge bg-{{ $submission->status->color() }}-subtle text-{{ $submission->status->color() }}">{{ $submission->status->label() }}</span>
                @if ($submission->submitted_at)
                    <small class="text-muted">{{ __('Handed in :time', ['time' => $school->localTime($submission->submitted_at)->format($school->setting('date_format').' '.$school->setting('time_format'))]) }}</small>
                @endif
            </div>
            @if ($submission->content)
                <div class="notice-body text-pre-line fs-14" style="max-height: 16rem; overflow-y: auto">{{ $submission->content }}</div>
            @endif
            @if ($submission->attachment)
                <a href="{{ route('tenant.homework.submission', $submission->id) }}" class="btn btn-sm btn-light mt-2">
                    <i class="ri-attachment-2 me-1"></i>{{ $submission->attachmentName() }}
                </a>
            @endif
        @endif
    </div>

    <div class="row">
        <div class="col-sm-4">
            <x-form.input name="marks" type="number" step="0.5" min="0" :max="$homework->max_marks" :label="__('Marks')"
                          :hint="$homework->max_marks !== null ? __('Out of :max', ['max' => Homeworks::number((float) $homework->max_marks)]) : __('Optional')" />
        </div>
        <div class="col-sm-8">
            <x-form.textarea name="feedback" :label="__('Feedback')" rows="4" maxlength="2000" :hint="__('The student and their parents see this.')" />
        </div>
    </div>

    @if ($submission && ($submission->isHandedIn() || $submission->isGraded()) && $homework->acceptsSubmissions())
        <x-slot:footer>
            <button type="button" class="btn btn-outline-warning me-auto" wire:click="sendBack" wire:loading.attr="disabled" wire:target="sendBack">
                <i class="ri-arrow-go-back-line me-1"></i>{{ __('Send back to redo') }}
            </button>
        </x-slot:footer>
    @endif
</x-modal.form>
