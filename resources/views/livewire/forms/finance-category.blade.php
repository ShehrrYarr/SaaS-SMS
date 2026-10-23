<?php

/*
 * Create / edit an income or expense category.
 *     $dispatch('open-modal', { component: 'forms.finance-category' })
 *     $dispatch('open-modal', { component: 'forms.finance-category', params: { category: 3 } })
 */

use App\Enums\FinanceCategoryType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\FinanceCategory;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['category' => null, 'system' => false])->locked();

state(['type' => '', 'name' => '', 'description' => '', 'is_active' => true]);

mount(function (?int $category = null) {
    if (! $category) {
        abort_if($this->types === [], 403);
        $this->type = (string) array_key_first($this->types);

        return;
    }

    $record = FinanceCategory::query()->findOrFail($category);
    $this->authorize($record->type->permission('edit'));

    $this->category = $record->id;
    $this->system = $record->isSystem();
    $this->fill([
        'type' => $record->type->value,
        'name' => $record->name,
        'description' => (string) $record->description,
        'is_active' => $record->is_active,
    ]);
});

// Ledgers the user may add categories to.
$types = computed(fn () => collect(FinanceCategoryType::cases())
    ->filter(fn (FinanceCategoryType $type) => auth()->user()->can($type->permission('edit')))
    ->mapWithKeys(fn (FinanceCategoryType $type) => [$type->value => $type->label()])
    ->all());

rules(fn () => [
    'type' => [$this->category ? 'nullable' : 'required', Rule::in(array_keys($this->types))],
    'name' => ['required', 'string', 'max:100', Rule::unique('finance_categories', 'name')->where('school_id', tenant()->id)->where('type', $this->type)->ignore($this->category)],
    'description' => ['nullable', 'string', 'max:500'],
    'is_active' => ['boolean'],
]);

$save = function () {
    $data = $this->validate();
    $values = [
        'name' => trim($data['name']),
        'description' => filled($data['description']) ? trim($data['description']) : null,
        // Automatic categories always stay on.
        'is_active' => $this->system ? true : $data['is_active'],
    ];

    if ($this->category) {
        $record = FinanceCategory::query()->findOrFail($this->category);
        $this->authorize($record->type->permission('edit'));
        $record->update($values);
    } else {
        $this->authorize(FinanceCategoryType::from($data['type'])->permission('edit'));
        FinanceCategory::query()->create([...$values, 'type' => $data['type']]);
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->category ? __('Category updated.') : __('Category created.'));
};

?>

<x-modal.form :title="$category ? __('Edit category') : __('New category')"
              :subtitle="$system ? __('Entries are posted to this category automatically. You can rename it.') : null">
    @unless ($category)
        <x-form.select name="type" :label="__('Ledger')" :options="$this->types" :placeholder="false" required />
    @endunless
    <x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. Utilities')" />
    <x-form.textarea name="description" :label="__('Description')" rows="2" maxlength="500" />
    @unless ($system)
        <x-form.switch name="is_active" :label="__('Active')" :hint="__('Inactive categories can\'t be chosen for new entries.')" wrapper="mb-0" />
    @endunless
</x-modal.form>
