<?php

/*
 * Subject allocation: pick a year and class, tick the subjects it studies,
 * and choose a teacher for each section.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\StaffProfile;
use App\Models\Subject;
use App\Services\Academics\SubjectAllocator;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, layout, mount, protect, state, title, updated, uses};

layout('layouts.app');
title(__('Subject allocation'));

uses([InteractsWithUi::class]);

state(['academic_year_id' => '', 'class_id' => '']);

// subject id => ['taught' => bool, 'periods' => '', 'teachers' => [section key => teacher id]]
state(['rows' => []]);

$years = computed(fn () => AcademicYear::query()->latest('start_date')->get());

$classes = computed(fn () => SchoolClass::query()->with(['sections' => fn ($query) => $query->ordered()])->ordered()->get());

$schoolClass = computed(fn () => $this->classes->firstWhere('id', (int) $this->class_id));

$subjects = computed(fn () => Subject::query()->orderBy('name')->get());

$teachers = computed(fn () => StaffProfile::query()
    ->with('user:id,name')
    ->get()
    ->sortBy(fn (StaffProfile $staff) => $staff->user?->name)
    ->mapWithKeys(fn (StaffProfile $staff) => [$staff->id => ($staff->user?->name ?? __('Unnamed')).' ('.$staff->employee_id.')'])
    ->all());

// Columns: one per section, or a single "all" column for a class without sections.
$sectionColumns = computed(fn () => $this->schoolClass && $this->schoolClass->sections->isNotEmpty()
    ? $this->schoolClass->sections->mapWithKeys(fn ($section) => [(string) $section->id => __('Section :name', ['name' => $section->name])])->all()
    : ['all' => __('Teacher')]);

$load = protect(function () {
    $year = $this->years->firstWhere('id', (int) $this->academic_year_id);
    $class = $this->schoolClass;
    $this->resetValidation();

    if (! $year || ! $class) {
        $this->rows = [];

        return;
    }

    $current = app(SubjectAllocator::class)->current($year, $class);
    $rows = [];

    foreach ($this->subjects as $subject) {
        $allocation = $current[$subject->id] ?? null;
        $teachers = [];

        foreach (array_keys($this->sectionColumns) as $key) {
            $teachers[$key] = (string) ($allocation['teachers'][$key] ?? '');
        }

        $rows[$subject->id] = [
            'taught' => $allocation !== null,
            'periods' => (string) ($allocation['periods'] ?? ''),
            'teachers' => $teachers,
        ];
    }

    $this->rows = $rows;
});

mount(function () {
    $this->authorize('subjects.allocate');

    $this->academic_year_id = (string) (AcademicYear::query()->current()->value('id') ?? $this->years->first()?->id ?? '');
    $this->class_id = (string) ($this->classes->first()?->id ?? '');
    $this->load();
});

updated([
    'academic_year_id' => fn () => $this->load(),
    'class_id' => function () {
        unset($this->schoolClass, $this->sectionColumns);
        $this->load();
    },
]);

$toggleAll = function () {
    $all = collect($this->rows)->every(fn ($row) => $row['taught']);

    foreach ($this->rows as $id => $row) {
        $this->rows[$id]['taught'] = ! $all;
    }
};

$save = function (SubjectAllocator $allocator) {
    $this->authorize('subjects.allocate');

    $teacherRule = Rule::exists('staff_profiles', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at');

    $this->validate([
        'academic_year_id' => ['required', Rule::exists('academic_years', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
        'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
        'rows' => ['array'],
        'rows.*.taught' => ['boolean'],
        'rows.*.periods' => ['nullable', 'integer', 'min:1', 'max:60'],
        'rows.*.teachers.*' => ['nullable', $teacherRule],
    ], [], ['rows.*.periods' => __('periods per week'), 'rows.*.teachers.*' => __('teacher')]);

    // Only subjects of this school, and only the ones ticked.
    $subjectIds = $this->subjects->pluck('id')->all();
    $taught = collect($this->rows)
        ->filter(fn ($row, $id) => ($row['taught'] ?? false) && in_array((int) $id, $subjectIds, true))
        ->all();

    $allocator->sync(
        $this->years->firstWhere('id', (int) $this->academic_year_id),
        $this->schoolClass,
        $taught,
    );

    $this->toast(trans_choice('{0} :class has no subjects now.|{1} :class studies :count subject.|[2,*] :class studies :count subjects.', count($taught), [
        'class' => $this->schoolClass->name,
        'count' => count($taught),
    ]));
};

?>

<div>
    <x-page-header :title="__('Subject allocation')" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Academic setup') => null, __('Subject allocation') => null]" />

    @if ($this->years->isEmpty() || $this->classes->isEmpty() || $this->subjects->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-book-shelf-line" :title="__('Nothing to allocate yet')"
                           :message="__('Subject allocation needs an academic year, classes and subjects.')">
                <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                    @if ($this->years->isEmpty())
                        <a href="{{ route('admin.academic-years.index') }}" wire:navigate class="btn btn-sm btn-light">{{ __('Academic years') }}</a>
                    @endif
                    @if ($this->classes->isEmpty())
                        <a href="{{ route('admin.classes.index') }}" wire:navigate class="btn btn-sm btn-light">{{ __('Classes & sections') }}</a>
                    @endif
                    @if ($this->subjects->isEmpty())
                        <a href="{{ route('admin.subjects.index') }}" wire:navigate class="btn btn-sm btn-light">{{ __('Subjects') }}</a>
                    @endif
                </div>
            </x-empty-state>
        </div>
    @else
        <form wire:submit="save" class="card mb-0">
            <div class="card-header d-flex flex-wrap align-items-end gap-3">
                <div>
                    <label class="form-label fs-13 mb-1" for="allocation-year">{{ __('Academic year') }}</label>
                    <select id="allocation-year" class="form-select" wire:model.live="academic_year_id">
                        @foreach ($this->years as $year)
                            <option value="{{ $year->id }}">{{ $year->name }}{{ $year->isCurrent() ? ' ('.__('current').')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label fs-13 mb-1" for="allocation-class">{{ __('Class') }}</label>
                    <select id="allocation-class" class="form-select" wire:model.live="class_id">
                        @foreach ($this->classes as $class)
                            <option value="{{ $class->id }}">{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ms-auto text-muted fs-13">
                    {{ trans_choice('{0} No subjects ticked|{1} :count subject ticked|[2,*] :count subjects ticked', collect($rows)->where('taught', true)->count(), ['count' => collect($rows)->where('taught', true)->count()]) }}
                </div>
            </div>

            @if (empty($this->teachers))
                <div class="alert alert-info border-0 rounded-0 mb-0 fs-13">
                    <i class="ri-information-line me-1"></i>{{ __('Teachers can be assigned once staff have been added.') }}
                </div>
            @endif

            <div class="table-responsive" wire:loading.class="opacity-50" wire:target="academic_year_id,class_id">
                <table class="table align-middle table-nowrap mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col" style="width: 1%">
                                <input type="checkbox" class="form-check-input" wire:click="toggleAll" aria-label="{{ __('Tick every subject') }}"
                                       @checked($rows && collect($rows)->every(fn ($row) => $row['taught']))>
                            </th>
                            <th scope="col">{{ __('Subject') }}</th>
                            <th scope="col" style="width: 8rem">{{ __('Periods / week') }}</th>
                            @foreach ($this->sectionColumns as $label)
                                <th scope="col" style="min-width: 12rem">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->subjects as $subject)
                            @php($taught = $rows[$subject->id]['taught'] ?? false)
                            <tr wire:key="allocation-{{ $class_id }}-{{ $subject->id }}" @class(['text-muted' => ! $taught])>
                                <td>
                                    <input type="checkbox" class="form-check-input" id="taught-{{ $subject->id }}" wire:model.live="rows.{{ $subject->id }}.taught">
                                </td>
                                <td>
                                    <label for="taught-{{ $subject->id }}" class="mb-0">
                                        <span class="fw-medium {{ $taught ? 'text-body' : '' }}">{{ $subject->name }}</span>
                                        <small class="text-muted ms-1">{{ $subject->code }}</small>
                                    </label>
                                </td>
                                <td>
                                    <input type="number" min="1" max="60" class="form-control form-control-sm @error('rows.'.$subject->id.'.periods') is-invalid @enderror"
                                           wire:model="rows.{{ $subject->id }}.periods" @disabled(! $taught) aria-label="{{ __('Periods per week for :subject', ['subject' => $subject->name]) }}">
                                </td>
                                @foreach ($this->sectionColumns as $key => $label)
                                    <td>
                                        <select class="form-select form-select-sm" wire:model="rows.{{ $subject->id }}.teachers.{{ $key }}" @disabled(! $taught || empty($this->teachers))
                                                aria-label="{{ __(':section teacher for :subject', ['section' => $label, 'subject' => $subject->name]) }}">
                                            <option value="">{{ __('No teacher yet') }}</option>
                                            @foreach ($this->teachers as $id => $name)
                                                <option value="{{ $id }}">{{ $name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ __('Save allocation') }}</span>
                    <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
                </button>
            </div>
        </form>
    @endif
</div>
