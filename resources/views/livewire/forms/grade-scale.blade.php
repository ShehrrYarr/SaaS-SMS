<?php

/*
 * Create / edit a grade scale. Each grade only needs the percentage it
 * starts from; the top of each band is worked out, so bands never overlap
 * or leave gaps.
 *     $dispatch('open-modal', { component: 'forms.grade-scale', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.grade-scale', params: { gradeScale: 2 }, size: 'lg' })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['gradeScale' => null])->locked();

state([
    'name' => '', 'is_default' => false,
    // [['grade' => 'A', 'from' => '80', 'point' => '3.7', 'remark' => 'Excellent']]
    'items' => [],
]);

mount(function (?int $gradeScale = null) {
    $this->authorize('grade-scales.manage');

    if (! $gradeScale) {
        $this->items = [
            ['grade' => 'A', 'from' => '80', 'point' => '', 'remark' => ''],
            ['grade' => 'B', 'from' => '65', 'point' => '', 'remark' => ''],
            ['grade' => 'C', 'from' => '50', 'point' => '', 'remark' => ''],
            ['grade' => 'D', 'from' => '33', 'point' => '', 'remark' => ''],
            ['grade' => 'F', 'from' => '0', 'point' => '', 'remark' => ''],
        ];

        return;
    }

    $record = GradeScale::query()->with('items')->findOrFail($gradeScale);

    $this->gradeScale = $record->id;
    $this->name = $record->name;
    $this->is_default = $record->is_default;
    $this->items = $record->items->map(fn (GradeScaleItem $item) => [
        'grade' => $item->grade,
        'from' => rtrim(rtrim((string) $item->min_percentage, '0'), '.'),
        'point' => $item->grade_point !== null ? rtrim(rtrim((string) $item->grade_point, '0'), '.') : '',
        'remark' => (string) $item->remark,
    ])->all();
});

$addItem = function () {
    if (count($this->items) < 15) {
        $this->items[] = ['grade' => '', 'from' => '', 'point' => '', 'remark' => ''];
    }
};

$removeItem = function (int $index) {
    unset($this->items[$index]);
    $this->items = array_values($this->items);
    $this->resetValidation();
};

rules(fn () => [
    'name' => ['required', 'string', 'max:100', Rule::unique('grade_scales', 'name')->where('school_id', tenant()->id)->ignore($this->gradeScale)],
    'is_default' => ['boolean'],
    'items' => ['required', 'array', 'min:2', 'max:15'],
    'items.*.grade' => ['required', 'string', 'max:5', 'distinct:ignore_case'],
    'items.*.from' => ['required', 'numeric', 'min:0', 'max:100', 'distinct'],
    'items.*.point' => ['nullable', 'numeric', 'min:0', 'max:10'],
    'items.*.remark' => ['nullable', 'string', 'max:100'],
])->attributes([
    'items.*.grade' => __('grade'),
    'items.*.from' => __('starting percentage'),
    'items.*.point' => __('grade point'),
])->messages([
    'items.*.grade.distinct' => __('Each grade needs a different name.'),
    'items.*.from.distinct' => __('Two grades can\'t start at the same percentage.'),
]);

$save = function () {
    $this->authorize('grade-scales.manage');
    $data = $this->validate();

    $items = collect($data['items'])->sortByDesc(fn ($item) => (float) $item['from'])->values();

    if ((float) $items->last()['from'] !== 0.0) {
        $this->addError('items', __('The lowest grade must start at 0%, so every percentage gets a grade.'));

        return;
    }

    DB::transaction(function () use ($data, $items) {
        if ($data['is_default']) {
            GradeScale::query()->where('is_default', true)->whereKeyNot($this->gradeScale)->get()->each->update(['is_default' => false]);
        }

        $scale = $this->gradeScale
            ? tap(GradeScale::query()->findOrFail($this->gradeScale))->update(['name' => $data['name'], 'is_default' => $data['is_default']])
            : GradeScale::query()->create(['name' => $data['name'], 'is_default' => $data['is_default'] || GradeScale::query()->doesntExist()]);

        $scale->items()->delete();

        $ceiling = 100.0;
        foreach ($items as $index => $item) {
            $from = round((float) $item['from'], 2);

            $scale->items()->create([
                'grade' => trim($item['grade']),
                'min_percentage' => $from,
                'max_percentage' => $index === 0 ? 100 : $ceiling,
                'grade_point' => filled($item['point']) ? $item['point'] : null,
                'remark' => filled($item['remark']) ? trim($item['remark']) : null,
                'sort_order' => $index,
            ]);

            $ceiling = max(0, round($from - 0.01, 2));
        }
    });

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->gradeScale ? __('Grade scale updated.') : __('Grade scale created.'));
};

?>

<x-modal.form :title="$gradeScale ? __('Edit grade scale') : __('New grade scale')"
              :subtitle="__('Grades already given on entered marks don\'t change; new and re-saved marks use the scale.')">
    <div class="row align-items-end">
        <div class="col-sm-8"><x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. Primary grading')" /></div>
        <div class="col-sm-4"><x-form.switch name="is_default" :label="__('Default scale')" /></div>
    </div>

    @error('items') <div class="alert alert-danger py-2 fs-13">{{ $message }}</div> @enderror

    <div class="table-responsive border rounded-2 mb-2">
        <table class="table table-sm align-middle mb-0">
            <thead class="bg-light bg-opacity-30">
                <tr>
                    <th scope="col" class="ps-3" style="width: 6rem">{{ __('Grade') }}</th>
                    <th scope="col" style="width: 8rem">{{ __('From %') }}</th>
                    <th scope="col" style="width: 7rem">{{ __('Grade point') }}</th>
                    <th scope="col">{{ __('Remark') }}</th>
                    <th scope="col" class="pe-3"><span class="visually-hidden">{{ __('Remove') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $index => $item)
                    <tr wire:key="grade-row-{{ $index }}">
                        <td class="ps-3">
                            <input type="text" maxlength="5" wire:model="items.{{ $index }}.grade" aria-label="{{ __('Grade') }}"
                                   @class(['form-control form-control-sm', 'is-invalid' => $errors->has("items.{$index}.grade")])>
                        </td>
                        <td>
                            <input type="number" min="0" max="100" step="0.01" wire:model="items.{{ $index }}.from" aria-label="{{ __('Starts at percentage') }}"
                                   @class(['form-control form-control-sm', 'is-invalid' => $errors->has("items.{$index}.from")])>
                        </td>
                        <td>
                            <input type="number" min="0" max="10" step="0.01" wire:model="items.{{ $index }}.point" aria-label="{{ __('Grade point') }}"
                                   @class(['form-control form-control-sm', 'is-invalid' => $errors->has("items.{$index}.point")])>
                        </td>
                        <td>
                            <input type="text" maxlength="100" wire:model="items.{{ $index }}.remark" aria-label="{{ __('Remark') }}" class="form-control form-control-sm"
                                   placeholder="{{ __('e.g. Excellent') }}">
                        </td>
                        <td class="text-end pe-3">
                            <button type="button" class="btn btn-subtle-danger icon-btn-sm" wire:click="removeItem({{ $index }})" aria-label="{{ __('Remove grade') }}"><i class="ri-close-line"></i></button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @foreach ($errors->get('items.*') as $messages)
        <div class="text-danger fs-13">{{ $messages[0] }}</div>
        @break
    @endforeach

    <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
        <button type="button" class="btn btn-sm btn-subtle-primary" wire:click="addItem"><i class="ri-add-line me-1"></i>{{ __('Add grade') }}</button>
        <small class="text-muted">{{ __('A grade runs from its percentage up to where the next grade starts. The lowest must start at 0.') }}</small>
    </div>
</x-modal.form>
