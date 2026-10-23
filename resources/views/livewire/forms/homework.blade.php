<?php

/*
 * Set or edit homework.
 *     $dispatch('open-modal', { component: 'forms.homework', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.homework', params: { homework: 5 }, size: 'lg' })
 *
 * The due time is entered in the school's timezone and stored in UTC.
 */

use App\Enums\HomeworkStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Homework;
use App\Models\Section;
use App\Models\SubjectClass;
use App\Services\Homework\Homeworks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['homework' => null, 'currentStatus' => null, 'currentAttachment' => null, 'hasWork' => false])->locked();

state([
    'subject_class_id' => '', 'section_id' => '', 'title' => '', 'description' => '',
    'assigned_on' => '', 'due_date' => '', 'due_time' => '16:00', 'max_marks' => '',
    'attachment' => null, 'remove_attachment' => false, 'publish' => true,
]);

$timezone = protect(fn () => tenant()->setting('timezone') ?: config('app.timezone'));

$allocations = computed(function () {
    $options = app(Homeworks::class)->allocationsFor(auth()->user());

    // Keep an older allocation (last year's, or another teacher's) selectable when editing.
    if ($this->homework && ! $options->contains('id', (int) $this->subject_class_id)) {
        $current = SubjectClass::withTrashed()->with(['subject', 'schoolClass', 'section'])->find($this->subject_class_id);
        $options = $current ? $options->push($current) : $options;
    }

    return $options->filter(fn (SubjectClass $allocation) => $allocation->schoolClass && $allocation->subject)
        ->mapWithKeys(fn (SubjectClass $allocation) => [
            $allocation->id => $allocation->schoolClass->name.($allocation->section ? ' · '.$allocation->section->name : '').' — '.$allocation->subject->name,
        ]);
});

// Sections to narrow to, when the allocation covers every section of its class.
$sections = computed(function () {
    $allocation = $this->subject_class_id ? SubjectClass::withTrashed()->find($this->subject_class_id) : null;

    if (! $allocation || $allocation->section_id) {
        return collect();
    }

    return Section::query()->where('class_id', $allocation->class_id)->ordered()->pluck('name', 'id');
});

mount(function (?int $homework = null) {
    $today = tenant()->today();

    if (! $homework) {
        $this->authorize('homework.create');
        $this->assigned_on = $today->toDateString();
        $this->due_date = $today->copy()->addDays(2)->toDateString();
        $this->subject_class_id = (string) ($this->allocations->keys()->first() ?? '');

        return;
    }

    $record = Homework::query()->with('subjectClass')->findOrFail($homework);
    $this->authorize('homework.edit');
    abort_unless(app(Homeworks::class)->looksAfter(auth()->user(), $record), 403);

    $due = tenant()->localTime($record->due_at);

    $this->homework = $record->id;
    $this->currentStatus = $record->status->value;
    $this->currentAttachment = $record->attachmentName();
    $this->hasWork = $record->submissions()->exists();
    $this->fill([
        'subject_class_id' => (string) $record->subject_class_id,
        'section_id' => (string) ($record->section_id ?? ''),
        'title' => $record->title,
        'description' => (string) $record->description,
        'assigned_on' => $record->assigned_on->toDateString(),
        'due_date' => $due->toDateString(),
        'due_time' => $due->format('H:i'),
        'max_marks' => $record->max_marks !== null ? Homeworks::number((float) $record->max_marks) : '',
        'publish' => $record->status !== HomeworkStatus::Draft,
    ]);
});

updated(['subject_class_id' => function () {
    unset($this->sections);
    $this->section_id = '';
}]);

rules(fn () => [
    'subject_class_id' => ['required', Rule::in($this->allocations->keys()->map(fn ($id) => (string) $id)->all())],
    'section_id' => ['nullable', Rule::in($this->sections->keys()->map(fn ($id) => (string) $id)->all())],
    'title' => ['required', 'string', 'max:255'],
    'description' => ['nullable', 'string', 'max:10000'],
    'assigned_on' => ['required', 'date'],
    'due_date' => ['required', 'date', 'after_or_equal:assigned_on'],
    'due_time' => ['required', 'date_format:H:i'],
    'max_marks' => ['nullable', 'numeric', 'min:1', 'max:1000'],
    'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,webp,zip'],
    'remove_attachment' => ['boolean'],
    'publish' => ['boolean'],
])->attributes([
    'subject_class_id' => __('class and subject'),
    'section_id' => __('section'),
    'assigned_on' => __('set on'),
    'due_date' => __('due date'),
    'due_time' => __('due time'),
    'max_marks' => __('marks'),
]);

$save = function (Homeworks $homeworks) {
    $this->authorize($this->homework ? 'homework.edit' : 'homework.create');

    $data = $this->validate();

    $attributes = [
        'subject_class_id' => (int) $data['subject_class_id'],
        'section_id' => $data['section_id'] !== '' && $data['section_id'] !== null ? (int) $data['section_id'] : null,
        'title' => trim($data['title']),
        'description' => filled($data['description']) ? trim($data['description']) : null,
        'assigned_on' => $data['assigned_on'],
        'due_at' => Carbon::parse($data['due_date'].' '.$data['due_time'], $this->timezone())->utc(),
        'max_marks' => filled($data['max_marks']) ? (float) $data['max_marks'] : null,
    ];

    $homework = DB::transaction(function () use ($attributes, $data, $homeworks) {
        if ($this->homework) {
            $homework = Homework::query()->with('subjectClass')->findOrFail($this->homework);
            abort_unless($homeworks->looksAfter(auth()->user(), $homework), 403);

            if ($this->hasWork) {
                // Work has been handed in for this class: keep it pointing at the same students.
                unset($attributes['subject_class_id'], $attributes['section_id']);
            }

            $homework->update($attributes);
        } else {
            $homework = Homework::query()->create($attributes + ['status' => HomeworkStatus::Draft, 'created_by' => auth()->id()]);
        }

        $homeworks->attach($homework, $this->attachment, (bool) $data['remove_attachment']);

        if ($data['publish']) {
            $homeworks->publish($homework);
        }

        return $homework;
    });

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('homework-updated');
    $this->toast(match (true) {
        (bool) $this->homework => __('Homework updated.'),
        $homework->isDraft() => __('Saved as a draft.'),
        default => __('Homework published.'),
    });
};

?>

<x-modal.form :title="$homework ? __('Edit homework') : __('Set homework')">
    <div class="row">
        <div class="col-md-{{ $this->sections->isNotEmpty() ? 8 : 12 }}">
            <x-form.select name="subject_class_id" :label="__('Class and subject')" :options="$this->allocations" required live
                           :disabled="$hasWork" :hint="$hasWork ? __('Work has been handed in, so the class can\'t change.') : null" />
        </div>
        @if ($this->sections->isNotEmpty())
            <div class="col-md-4">
                <x-form.select name="section_id" :label="__('Section')" :options="$this->sections" :placeholder="__('Every section')" :disabled="$hasWork" />
            </div>
        @endif
    </div>

    <x-form.input name="title" :label="__('Title')" required maxlength="255" />

    <x-form.textarea name="description" :label="__('Instructions')" rows="5" maxlength="10000" />

    <div class="row">
        <div class="col-sm-6 col-md-3"><x-form.input name="assigned_on" type="date" :label="__('Set on')" required /></div>
        <div class="col-sm-6 col-md-3"><x-form.input name="due_date" type="date" :label="__('Due date')" required /></div>
        <div class="col-sm-6 col-md-3"><x-form.input name="due_time" type="time" :label="__('Due time')" required /></div>
        <div class="col-sm-6 col-md-3"><x-form.input name="max_marks" type="number" min="1" max="1000" step="0.5" :label="__('Out of')" :hint="__('Optional')" /></div>
    </div>

    <div class="mb-4">
        <label class="form-label" for="field-attachment">{{ __('Worksheet or file') }}</label>
        @if ($currentAttachment && ! $remove_attachment && ! $attachment)
            <div class="d-flex align-items-center gap-2 mb-2 fs-13">
                <i class="ri-attachment-2 text-muted"></i><span>{{ $currentAttachment }}</span>
                <button type="button" class="btn btn-sm btn-link text-danger p-0" wire:click="$set('remove_attachment', true)">{{ __('Remove') }}</button>
            </div>
        @endif
        <input type="file" id="field-attachment" wire:model="attachment"
               @class(['form-control', 'is-invalid' => $errors->has('attachment')]) accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.webp,.zip">
        <div wire:loading wire:target="attachment" class="form-text"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Uploading…') }}</div>
        @error('attachment') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">{{ __('Optional. Documents, images or a zip, up to 10 MB.') }}</div>
    </div>

    @if (! $homework || $currentStatus === HomeworkStatus::Draft->value)
        <x-form.switch name="publish" :label="__('Publish now: students and their parents are told')" wrapper="mb-0"
                       :hint="__('Leave off to save a draft only you can see.')" />
    @endif
</x-modal.form>
