<?php

/*
 * Student and parent portals: one homework — the brief, and the student's
 * work. Students hand it in here until it is graded or closed; parents see
 * it read-only.
 */

use App\Enums\SubmissionStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Services\Homework\Homeworks;
use App\Services\Portals\StudentOverview;
use App\Support\Portal;
use App\Support\SelfService;
use App\Tables\ActionFailed;
use Livewire\WithFileUploads;

use function Livewire\Volt\{computed, layout, mount, state, title, uses};

layout('layouts.app');
title(__('Homework'));

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['homework' => null])->locked();

state(['content' => '', 'attachment' => null, 'remove_attachment' => false, 'editing' => false]);

mount(function (int $homework) {
    $this->homework = $homework;
    $this->content = (string) ($this->submission?->content ?? '');
    $this->editing = $this->canSubmit && ! $this->submission;
});

$student = computed(fn () => SelfService::student());

// The homework, if it was set for this student.
$record = computed(fn () => $this->student
    ? app(StudentOverview::class)->homework($this->student)->with('creator')->find($this->homework)
    : null);

$submission = computed(fn () => $this->record?->submissions->first());

$canSubmit = computed(fn () => Portal::current() === 'student'
    && $this->record?->acceptsSubmissions()
    && ! $this->submission?->isGraded());

$submit = function (Homeworks $homeworks) {
    abort_unless($this->canSubmit, 403);

    $this->validate([
        'content' => ['nullable', 'string', 'max:20000'],
        'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,webp,zip'],
        'remove_attachment' => ['boolean'],
    ], [], ['content' => __('answer'), 'attachment' => __('file')]);

    try {
        $submission = $homeworks->submit($this->record, $this->student, $this->content, $this->attachment, $this->remove_attachment);
    } catch (ActionFailed $e) {
        $this->addError('content', $e->getMessage());

        return;
    }

    unset($this->record, $this->submission, $this->canSubmit);
    $this->reset('attachment', 'remove_attachment', 'editing');
    $this->toast($submission->status === SubmissionStatus::Late ? __('Handed in late.') : __('Handed in. Well done!'));
};

?>

@php
    $portal = Portal::current();
    $homework = $this->record;
    $submission = $this->submission;
    $school = tenant();
    $stamp = $school->setting('date_format').' '.$school->setting('time_format');
@endphp

<div>
    <x-page-header :title="$homework?->title ?? __('Homework')"
                   :breadcrumbs="SelfService::breadcrumbs([__('Homework') => route($portal.'.homework.index'), ($homework?->title ?? __('Homework')) => null])" />

    @if (! $this->student)
        <x-family.missing />
    @elseif (! $homework)
        <div class="card mb-0">
            <x-empty-state icon="ri-book-open-line" :title="__('Homework not found')"
                           :message="__('It may have been removed, or set for a different class.')">
                <a href="{{ route($portal.'.homework.index') }}" wire:navigate class="btn btn-light mt-4">{{ __('Back to homework') }}</a>
            </x-empty-state>
        </div>
    @else
        <div class="row g-4">
            <div class="col-xl-7">
                <div class="card mb-0 h-100">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                            <span class="badge bg-primary-subtle text-primary">{{ $homework->subjectClass?->subject?->name }}</span>
                            @if ($homework->isClosed())<span class="badge bg-dark-subtle text-dark">{{ __('Closed') }}</span>@endif
                            @if ($homework->subjectClass?->teacher?->user)
                                <span class="text-muted fs-13"><i class="ri-user-star-line me-1"></i>{{ $homework->subjectClass->teacher->user->name }}</span>
                            @endif
                        </div>
                        <dl class="row fs-13 mb-3">
                            <dt class="col-sm-3 text-muted fw-normal">{{ __('Due') }}</dt>
                            <dd @class(['col-sm-9 fw-medium', 'text-danger' => $homework->isPublished() && $homework->isPastDue() && ! $submission])>{{ $homework->dueLabel() }}</dd>
                            <dt class="col-sm-3 text-muted fw-normal">{{ __('Set on') }}</dt>
                            <dd class="col-sm-9">{{ $homework->assigned_on->format($school->setting('date_format')) }}</dd>
                            @if ($homework->max_marks !== null)
                                <dt class="col-sm-3 text-muted fw-normal">{{ __('Marks') }}</dt>
                                <dd class="col-sm-9">{{ __('Out of :max', ['max' => Homeworks::number((float) $homework->max_marks)]) }}</dd>
                            @endif
                        </dl>
                        @if ($homework->description)
                            <div class="notice-body text-pre-line border-top pt-3">{{ $homework->description }}</div>
                        @endif
                        @if ($homework->attachment)
                            <a href="{{ route('tenant.homework.attachment', $homework->id) }}" class="btn btn-sm btn-light mt-3">
                                <i class="ri-attachment-2 me-1"></i>{{ $homework->attachmentName() }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card mb-0 h-100">
                    <div class="card-header hstack gap-2">
                        <h5 class="card-title mb-0 flex-grow-1">{{ $portal === 'student' ? __('My work') : __('Work handed in') }}</h5>
                        @if ($submission)
                            <span class="badge bg-{{ $submission->status->color() }}-subtle text-{{ $submission->status->color() }}">{{ $submission->status === SubmissionStatus::Returned ? __('Sent back') : $submission->status->label() }}</span>
                        @endif
                    </div>
                    <div class="card-body">
                        @if ($submission && ($submission->isGraded() || $submission->status === SubmissionStatus::Returned))
                            <div @class(['rounded p-3 mb-3', 'bg-success-subtle' => $submission->isGraded(), 'bg-warning-subtle' => ! $submission->isGraded()])>
                                @if ($submission->isGraded() && $submission->marks !== null)
                                    <div class="fs-4 fw-semibold">{{ Homeworks::number((float) $submission->marks) }}@if ($homework->max_marks !== null)<span class="fs-6 text-muted fw-normal"> / {{ Homeworks::number((float) $homework->max_marks) }}</span>@endif</div>
                                @endif
                                @if ($submission->feedback)
                                    <p class="mb-0 text-pre-line fs-14">{{ $submission->feedback }}</p>
                                @endif
                                @if (! $submission->isGraded())
                                    <small class="d-block mt-2">{{ __('Your teacher sent this back. Make the changes and hand it in again.') }}</small>
                                @endif
                            </div>
                        @endif

                        @if ($editing && $this->canSubmit)
                            <form wire:submit="submit" novalidate>
                                <x-form.textarea name="content" :label="__('Answer')" rows="6" maxlength="20000" :hint="__('Type your answer, attach a file, or both.')" />

                                <div class="mb-4">
                                    <label class="form-label" for="field-attachment">{{ __('File') }}</label>
                                    @if ($submission?->attachment && ! $remove_attachment && ! $attachment)
                                        <div class="d-flex align-items-center gap-2 mb-2 fs-13">
                                            <i class="ri-attachment-2 text-muted"></i><span>{{ $submission->attachmentName() }}</span>
                                            <button type="button" class="btn btn-sm btn-link text-danger p-0" wire:click="$set('remove_attachment', true)">{{ __('Remove') }}</button>
                                        </div>
                                    @endif
                                    <input type="file" id="field-attachment" wire:model="attachment" @class(['form-control', 'is-invalid' => $errors->has('attachment')])
                                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.webp,.zip">
                                    <div wire:loading wire:target="attachment" class="form-text"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Uploading…') }}</div>
                                    @error('attachment') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div class="form-text">{{ __('Up to 10 MB: documents, photos of your work, or a zip.') }}</div>
                                </div>

                                @if ($homework->isPastDue())
                                    <div class="alert alert-warning py-2 fs-13"><i class="ri-time-line me-1"></i>{{ __('The due time has passed, so this will be marked late.') }}</div>
                                @endif

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="submit,attachment">
                                        <span wire:loading.remove wire:target="submit"><i class="ri-upload-2-line me-1"></i>{{ $submission ? __('Hand in again') : __('Hand in') }}</span>
                                        <span wire:loading wire:target="submit"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Sending…') }}</span>
                                    </button>
                                    @if ($submission)
                                        <button type="button" class="btn btn-light" wire:click="$set('editing', false)">{{ __('Cancel') }}</button>
                                    @endif
                                </div>
                            </form>
                        @elseif ($submission && ($submission->content || $submission->attachment))
                            @if ($submission->submitted_at)
                                <p class="text-muted fs-13 mb-2">{{ __('Handed in :time', ['time' => $school->localTime($submission->submitted_at)->format($stamp)]) }}</p>
                            @endif
                            @if ($submission->content)
                                <div class="notice-body text-pre-line border rounded p-3 mb-2" style="max-height: 18rem; overflow-y: auto">{{ $submission->content }}</div>
                            @endif
                            @if ($submission->attachment)
                                <a href="{{ route('tenant.homework.submission', $submission->id) }}" class="btn btn-sm btn-light"><i class="ri-attachment-2 me-1"></i>{{ $submission->attachmentName() }}</a>
                            @endif
                            @if ($this->canSubmit)
                                <div class="mt-3">
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="$set('editing', true)">
                                        <i class="ri-pencil-line me-1"></i>{{ $submission->status === SubmissionStatus::Returned ? __('Redo and hand in again') : __('Change my work') }}
                                    </button>
                                </div>
                            @endif
                        @elseif (! $submission?->isGraded())
                            <x-empty-state icon="ri-inbox-line" class="py-4"
                                           :title="$homework->isClosed() ? __('Closed without a submission') : __('Not handed in yet')"
                                           :message="$portal === 'parent' ? __('Work your child hands in online shows here.') : null" />
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
