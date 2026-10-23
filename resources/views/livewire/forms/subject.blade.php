<?php

/*
 * Create / edit a subject.
 *     $dispatch('open-modal', { component: 'forms.subject', params: { subject: 2 } })
 */

use App\Enums\SubjectType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Subject;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['subject' => null])->locked();

state(['code' => '', 'name' => '', 'type' => SubjectType::Theory->value, 'description' => '']);

mount(function (?int $subject = null) {
    if (! $subject) {
        $this->authorize('subjects.create');

        return;
    }

    $this->authorize('subjects.edit');
    $record = Subject::query()->findOrFail($subject);

    $this->subject = $record->id;
    $this->fill([...$record->only(['code', 'name', 'description']), 'type' => $record->type->value]);
});

rules(fn () => [
    'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9._-]+$/', Rule::unique('subjects', 'code')->where('school_id', tenant()->id)->ignore($this->subject)],
    'name' => ['required', 'string', 'max:100'],
    'type' => ['required', Rule::enum(SubjectType::class)],
    'description' => ['nullable', 'string', 'max:1000'],
])->messages([
    'code.regex' => __('Use capital letters, numbers, dots, dashes or underscores.'),
]);

$save = function () {
    $this->authorize($this->subject ? 'subjects.edit' : 'subjects.create');
    $this->code = Str::upper(trim($this->code));
    $data = array_map(fn ($value) => $value === '' ? null : $value, $this->validate());

    $this->subject
        ? Subject::query()->findOrFail($this->subject)->update($data)
        : Subject::query()->create($data);

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->subject ? __('Subject updated.') : __('Subject created.'));
};

?>

<x-modal.form :title="$subject ? __('Edit subject') : __('New subject')">
    <div class="row">
        <div class="col-sm-4"><x-form.input name="code" :label="__('Code')" required maxlength="20" :placeholder="__('e.g. MATH')" class="text-uppercase" /></div>
        <div class="col-sm-8"><x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. Mathematics')" /></div>
    </div>

    <x-form.select name="type" :label="__('Type')" :options="SubjectType::options()" :placeholder="null" required
                   :hint="__('Optional subjects are taken by some students only.')" />

    <x-form.textarea name="description" :label="__('Description')" rows="2" maxlength="1000" wrapper="mb-0" />
</x-modal.form>
