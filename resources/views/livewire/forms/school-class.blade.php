<?php

/*
 * Create / edit a class together with its sections.
 *     $dispatch('open-modal', { component: 'forms.school-class', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.school-class', params: { schoolClass: 4 }, size: 'lg' })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campus;
use App\Models\Classroom;
use App\Models\SchoolClass;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['schoolClass' => null])->locked();

state([
    'campus_id' => '', 'name' => '', 'code' => '', 'numeric_level' => '', 'capacity' => '',
    // [['id' => ?int, 'name' => 'A', 'capacity' => '', 'classroom_id' => '']]
    'sections' => [],
    'quickSections' => '',
]);

$campuses = computed(fn () => Campus::query()->ordered()->pluck('name', 'id')->all());

$classrooms = computed(fn () => Classroom::query()
    ->when($this->campus_id, fn ($query) => $query->where('campus_id', $this->campus_id))
    ->orderBy('room_number')
    ->get()
    ->mapWithKeys(fn (Classroom $room) => [$room->id => $room->room_number.($room->name ? " · {$room->name}" : '')])
    ->all());

mount(function (?int $schoolClass = null) {
    if (! $schoolClass) {
        $this->authorize('classes.create');
        $this->campus_id = (string) (Campus::main()?->id ?? '');
        $this->numeric_level = (string) (((int) SchoolClass::query()->max('numeric_level')) + 1);
        $this->sections = [['id' => null, 'name' => 'A', 'capacity' => '', 'classroom_id' => '']];

        return;
    }

    $this->authorize('classes.edit');
    $record = SchoolClass::query()->with(['sections' => fn ($query) => $query->ordered()])->findOrFail($schoolClass);

    $this->schoolClass = $record->id;
    $this->fill([
        'campus_id' => (string) $record->campus_id,
        'name' => $record->name,
        'code' => (string) $record->code,
        'numeric_level' => (string) ($record->numeric_level ?? ''),
        'capacity' => (string) ($record->capacity ?? ''),
        'sections' => $record->sections->map(fn (Section $section) => [
            'id' => $section->id,
            'name' => $section->name,
            'capacity' => (string) ($section->capacity ?? ''),
            'classroom_id' => (string) ($section->classroom_id ?? ''),
        ])->all(),
    ]);
});

$addSection = function () {
    $used = collect($this->sections)->pluck('name')->map(fn ($name) => strtoupper((string) $name))->all();
    $next = collect(range('A', 'Z'))->first(fn (string $letter) => ! in_array($letter, $used, true)) ?? '';

    $this->sections[] = ['id' => null, 'name' => $next, 'capacity' => '', 'classroom_id' => ''];
};

// "A, B, C" or "Blue, Green" adds several sections at once.
$addQuickSections = function () {
    $existing = collect($this->sections)->pluck('name')->map(fn ($name) => mb_strtolower(trim((string) $name)));

    collect(preg_split('/[,;\n]+/', $this->quickSections))
        ->map(fn ($name) => trim($name))
        ->filter()
        ->unique(fn ($name) => mb_strtolower($name))
        ->reject(fn ($name) => $existing->contains(mb_strtolower($name)))
        ->each(fn ($name) => $this->sections[] = ['id' => null, 'name' => mb_substr($name, 0, 30), 'capacity' => '', 'classroom_id' => '']);

    $this->quickSections = '';
};

$removeSection = function (int $index) {
    unset($this->sections[$index]);
    $this->sections = array_values($this->sections);
    $this->resetValidation();
};

rules(fn () => [
    'campus_id' => ['required', Rule::exists('campuses', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'name' => ['required', 'string', 'max:50', Rule::unique('classes', 'name')->where('school_id', tenant()->id)->where('campus_id', $this->campus_id ?: 0)->ignore($this->schoolClass)],
    'code' => ['nullable', 'string', 'max:20'],
    'numeric_level' => ['nullable', 'integer', 'min:0', 'max:255'],
    'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
    'sections' => ['array', 'max:26'],
    // Kept so existing sections are recognised (looked up within this class on save).
    'sections.*.id' => ['nullable', 'integer'],
    'sections.*.name' => ['required', 'string', 'max:30', 'distinct:ignore_case'],
    'sections.*.capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
    'sections.*.classroom_id' => ['nullable', Rule::exists('classrooms', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
])->attributes([
    'campus_id' => __('campus'),
    'numeric_level' => __('level'),
    'sections.*.name' => __('section name'),
    'sections.*.capacity' => __('section capacity'),
    'sections.*.classroom_id' => __('room'),
])->messages([
    'sections.*.name.distinct' => __('Each section needs a different name.'),
]);

$save = function () {
    $this->authorize($this->schoolClass ? 'classes.edit' : 'classes.create');
    $data = $this->validate();
    $blank = fn ($value) => $value === '' || $value === null ? null : $value;

    DB::transaction(function () use ($data, $blank) {
        $attributes = [
            'campus_id' => $data['campus_id'],
            'name' => $data['name'],
            'code' => $blank($data['code']),
            'numeric_level' => $blank($data['numeric_level']),
            'capacity' => $blank($data['capacity']),
        ];

        $class = $this->schoolClass
            ? tap(SchoolClass::query()->findOrFail($this->schoolClass))->update($attributes)
            : SchoolClass::query()->create($attributes);

        $keep = collect($data['sections'])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        // Sections taken off the list are deleted, unless students are in them.
        foreach ($class->sections()->whereKeyNot($keep)->get() as $removed) {
            if ($removed->isInUse()) {
                throw ValidationException::withMessages(['sections' => __('Section :name has students, so it can\'t be removed.', ['name' => $removed->name])]);
            }

            $removed->delete();
        }

        foreach ($data['sections'] as $row) {
            $values = ['name' => trim($row['name']), 'capacity' => $blank($row['capacity']), 'classroom_id' => $blank($row['classroom_id'])];

            if ($row['id'] ?? null) {
                $class->sections()->whereKey($row['id'])->firstOrFail()->update($values);

                continue;
            }

            // A section deleted earlier keeps its name; bring it back instead.
            $trashed = $class->sections()->onlyTrashed()->where('name', $values['name'])->first();

            $trashed
                ? tap($trashed)->restore()->update($values)
                : $class->sections()->create($values);
        }
    });

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->schoolClass ? __('Class updated.') : __('Class created.'));
};

?>

<x-modal.form :title="$schoolClass ? __('Edit class') : __('New class')"
              :subtitle="__('A class (grade) and its sections. Students are placed in a section.')">
    @if (empty($this->campuses))
        <div class="alert alert-warning mb-0">
            {{ __('Add a campus first.') }}
            <a href="{{ route('admin.campuses.index') }}" wire:navigate class="alert-link">{{ __('Campuses') }}</a>
        </div>
    @else
        <div class="row">
            <div class="col-sm-6"><x-form.input name="name" :label="__('Class name')" required maxlength="50" :placeholder="__('e.g. Grade 1')" /></div>
            <div class="col-sm-3"><x-form.input name="code" :label="__('Code')" maxlength="20" /></div>
            <div class="col-sm-3"><x-form.input name="numeric_level" type="number" min="0" max="255" :label="__('Level')"
                                                :hint="__('Orders classes and drives promotion.')" /></div>
            @if (count($this->campuses) > 1)
                <div class="col-sm-6"><x-form.select name="campus_id" :label="__('Campus')" :options="$this->campuses" required live /></div>
            @endif
            <div class="col-sm-6"><x-form.input name="capacity" type="number" min="1" :label="__('Total capacity')" /></div>
        </div>

        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-2">
            <div>
                <h6 class="mb-0">{{ __('Sections') }}</h6>
                <small class="text-muted">{{ __('Leave empty if the class isn\'t split into sections.') }}</small>
            </div>
            <div class="input-group input-group-sm" style="max-width: 16rem">
                <input type="text" class="form-control" wire:model="quickSections" wire:keydown.enter.prevent="addQuickSections"
                       placeholder="{{ __('Add several: A, B, C') }}" aria-label="{{ __('Section names, separated by commas') }}">
                <button type="button" class="btn btn-light" wire:click="addQuickSections">{{ __('Add') }}</button>
            </div>
        </div>

        @error('sections') <div class="alert alert-danger py-2 fs-13">{{ $message }}</div> @enderror

        @if ($sections)
            <div class="table-responsive border rounded-2 mb-2">
                <table class="table table-sm align-middle mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col" class="ps-3">{{ __('Name') }}</th>
                            <th scope="col" style="width: 7rem">{{ __('Capacity') }}</th>
                            <th scope="col">{{ __('Room') }}</th>
                            <th scope="col" class="text-end pe-3"><span class="visually-hidden">{{ __('Remove') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sections as $index => $section)
                            <tr wire:key="section-row-{{ $section['id'] ?? 'new-'.$index }}">
                                <td class="ps-3">
                                    <input type="text" maxlength="30" wire:model="sections.{{ $index }}.name" aria-label="{{ __('Section name') }}"
                                           @class(['form-control form-control-sm', 'is-invalid' => $errors->has("sections.{$index}.name")])>
                                    @error("sections.{$index}.name") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </td>
                                <td>
                                    <input type="number" min="1" wire:model="sections.{{ $index }}.capacity" aria-label="{{ __('Capacity') }}"
                                           @class(['form-control form-control-sm', 'is-invalid' => $errors->has("sections.{$index}.capacity")])>
                                </td>
                                <td>
                                    <select wire:model="sections.{{ $index }}.classroom_id" class="form-select form-select-sm" aria-label="{{ __('Room') }}">
                                        <option value="">{{ __('No fixed room') }}</option>
                                        @foreach ($this->classrooms as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-end pe-3">
                                    <button type="button" class="btn btn-subtle-danger icon-btn-sm" wire:click="removeSection({{ $index }})"
                                            aria-label="{{ __('Remove section :name', ['name' => $section['name']]) }}">
                                        <i class="ri-close-line"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <button type="button" class="btn btn-sm btn-subtle-primary" wire:click="addSection">
            <i class="ri-add-line me-1"></i>{{ __('Add section') }}
        </button>
    @endif
</x-modal.form>
