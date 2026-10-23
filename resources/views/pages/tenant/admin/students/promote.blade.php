<?php

/*
 * Year-end promotion: pick a class (and section) in one academic year,
 * decide each student's outcome, and move them into the next year.
 */

use App\Enums\EnrollmentStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Services\Students\PromoteStudents;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, layout, mount, protect, state, title, updated, uses};

layout('layouts.app');
title(__('Student promotion'));

uses([InteractsWithUi::class]);

state([
    'from_year' => '', 'from_class' => '', 'from_section' => '',
    'to_year' => '', 'to_class' => '', 'to_section' => '',
    // enrollment id => outcome
    'decisions' => [],
]);

$years = computed(fn () => AcademicYear::query()->orderByDesc('start_date')->get());

$classes = computed(fn () => SchoolClass::query()->ordered()->with(['sections' => fn ($query) => $query->ordered()])->get());

$fromSections = computed(fn () => $this->classes->firstWhere('id', (int) $this->from_class)?->sections ?? collect());

$toSections = computed(fn () => $this->classes->firstWhere('id', (int) $this->to_class)?->sections ?? collect());

$enrollments = computed(function () {
    if (! $this->from_year || ! $this->from_class) {
        return collect();
    }

    return StudentEnrollment::query()
        ->with(['student.user', 'section'])
        ->where('academic_year_id', (int) $this->from_year)
        ->where('class_id', (int) $this->from_class)
        ->when($this->from_section, fn ($query) => $query->where('section_id', (int) $this->from_section))
        ->where('status', EnrollmentStatus::Active)
        ->whereHas('student')
        ->get()
        ->sortBy(fn (StudentEnrollment $enrollment) => [$enrollment->section?->name, mb_strtolower((string) $enrollment->student?->user?->name)])
        ->values();
});

// Students already enrolled in the target year are left alone.
$alreadyEnrolled = computed(fn () => $this->to_year && $this->enrollments->isNotEmpty()
    ? StudentEnrollment::query()->where('academic_year_id', (int) $this->to_year)
        ->whereIn('student_profile_id', $this->enrollments->pluck('student_profile_id'))
        ->pluck('student_profile_id')->all()
    : []);

// The class one level up, if there is one.
$nextClass = protect(function (): ?SchoolClass {
    $from = $this->classes->firstWhere('id', (int) $this->from_class);

    if (! $from || $from->numeric_level === null) {
        return null;
    }

    return $this->classes->first(fn (SchoolClass $class) => $class->numeric_level !== null && $class->numeric_level > $from->numeric_level && $class->campus_id === $from->campus_id)
        ?? $this->classes->first(fn (SchoolClass $class) => $class->numeric_level !== null && $class->numeric_level > $from->numeric_level);
});

$resetDecisions = protect(function () {
    unset($this->enrollments, $this->alreadyEnrolled);

    $default = $this->to_class ? PromoteStudents::PROMOTE : PromoteStudents::GRADUATE;
    $this->decisions = $this->enrollments->mapWithKeys(fn (StudentEnrollment $enrollment) => [$enrollment->id => $default])->all();
});

mount(function () {
    $this->authorize('students.promote');

    $current = AcademicYear::query()->current()->first();
    $previous = $current
        ? AcademicYear::query()->whereDate('start_date', '<', $current->start_date)->orderByDesc('start_date')->first()
        : null;

    $this->to_year = (string) ($current?->id ?? '');
    $this->from_year = (string) (($previous ?? $current)?->id ?? '');
});

updated([
    'from_year' => fn () => $this->resetDecisions(),
    'from_class' => function () {
        $this->from_section = '';
        $next = $this->nextClass();
        $this->to_class = (string) ($next?->id ?? '');
        $this->to_section = '';
        $this->resetDecisions();
    },
    'from_section' => function () {
        // Keep the same section name in the new class when it exists.
        $name = $this->fromSections->firstWhere('id', (int) $this->from_section)?->name;
        $this->to_section = (string) ($this->toSections->firstWhere('name', $name)?->id ?? '');
        $this->resetDecisions();
    },
    'to_year' => function () {
        unset($this->alreadyEnrolled);
    },
    'to_class' => function () {
        $this->to_section = '';
        $this->resetDecisions();
    },
]);

$setAll = function (string $outcome) {
    if (! array_key_exists($outcome, PromoteStudents::outcomes())) {
        return;
    }

    $this->decisions = array_map(fn () => $outcome, $this->decisions);
};

$promote = function (PromoteStudents $promotion) {
    $this->authorize('students.promote');

    $this->validate([
        'from_year' => ['required', 'integer'],
        'to_year' => ['required', 'integer', 'different:from_year'],
        'from_class' => ['required', 'integer'],
        'to_class' => [in_array(PromoteStudents::PROMOTE, $this->decisions, true) ? 'required' : 'nullable', 'integer'],
        'to_section' => [$this->to_class && $this->toSections->isNotEmpty() && in_array(PromoteStudents::PROMOTE, $this->decisions, true) ? 'required' : 'nullable', 'integer'],
        'decisions' => ['required', 'array'],
        'decisions.*' => ['required', 'in:'.implode(',', array_keys(PromoteStudents::outcomes()))],
    ], [
        'to_year.different' => __('Choose a different year to promote into.'),
        'decisions.required' => __('There are no students to promote.'),
    ], [
        'from_year' => __('academic year'), 'to_year' => __('academic year'),
        'from_class' => __('class'), 'to_class' => __('class'), 'to_section' => __('section'),
    ]);

    try {
        $counts = $promotion->handle(
            AcademicYear::query()->findOrFail($this->from_year),
            AcademicYear::query()->findOrFail($this->to_year),
            $this->to_class ? SchoolClass::query()->findOrFail($this->to_class) : null,
            $this->to_section ? Section::query()->findOrFail($this->to_section) : null,
            $this->decisions,
            auth()->user(),
        );
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    $done = $counts[PromoteStudents::PROMOTE] + $counts[PromoteStudents::RETAIN] + $counts[PromoteStudents::GRADUATE] + $counts[PromoteStudents::LEAVE];

    $this->toast(trans_choice('{0} Nobody was moved.|{1} :count student moved on.|[2,*] :count students moved on.', $done, ['count' => $done])
        .($counts['skipped'] ? ' '.__(':count skipped (already enrolled).', ['count' => $counts['skipped']]) : ''));

    $this->resetDecisions();
};

?>

@php
    $yearOptions = $this->years->mapWithKeys(fn ($year) => [$year->id => $year->name.($year->isCurrent() ? ' ('.__('current').')' : '')])->all();
    $classOptions = $this->classes->mapWithKeys(fn ($class) => [$class->id => $class->name])->all();
    $outcomes = App\Services\Students\PromoteStudents::outcomes();
    $tally = collect($decisions)->countBy();
@endphp

<div>
    <x-page-header :title="__('Student promotion')"
                   :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Students') => route('admin.students.index'), __('Promotion') => null]" />

    <p class="text-muted mt-n3 mb-4">{{ __('At the start of a new academic year, make it the current year, then move each class up from last year. Past attendance and results keep their old class.') }}</p>

    @if ($this->years->count() < 2)
        <div class="alert alert-warning d-flex gap-2 align-items-start">
            <i class="ri-error-warning-line fs-5 lh-1"></i>
            <span>{{ __('Promotion moves students from one academic year to another. Add the next academic year first.') }}
                @can('academic-years.view')<a href="{{ route('admin.academic-years.index') }}" wire:navigate class="alert-link">{{ __('Academic years') }}</a>@endcan
            </span>
        </div>
    @endif

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card mb-0 h-100">
                <div class="card-header"><h5 class="card-title mb-0"><i class="ri-logout-box-line me-1 text-muted"></i>{{ __('From') }}</h5></div>
                <div class="card-body">
                    <x-form.select name="from_year" :label="__('Academic year')" :options="$yearOptions" required live />
                    <div class="row">
                        <div class="col-sm-6"><x-form.select name="from_class" :label="__('Class')" :options="$classOptions" required live wrapper="mb-4 mb-sm-0" /></div>
                        <div class="col-sm-6">
                            <x-form.select name="from_section" :label="__('Section')" :options="$this->fromSections->pluck('name', 'id')->all()" :placeholder="__('All sections')" live wrapper="mb-0"
                                           :disabled="$this->fromSections->isEmpty()" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-0 h-100">
                <div class="card-header"><h5 class="card-title mb-0"><i class="ri-login-box-line me-1 text-muted"></i>{{ __('Into') }}</h5></div>
                <div class="card-body">
                    <x-form.select name="to_year" :label="__('Academic year')" :options="$yearOptions" required live />
                    <div class="row">
                        <div class="col-sm-6"><x-form.select name="to_class" :label="__('Class')" :options="$classOptions" :placeholder="__('None (final class)')" live wrapper="mb-4 mb-sm-0" /></div>
                        <div class="col-sm-6">
                            <x-form.select name="to_section" :label="__('Section')" :options="$this->toSections->pluck('name', 'id')->all()" :placeholder="$this->toSections->isEmpty() ? __('No sections') : __('Select…')" wrapper="mb-0"
                                           :disabled="$this->toSections->isEmpty()" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($from_class && $from_year)
        <div class="card mb-0">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <h5 class="card-title mb-0 flex-grow-1">
                    {{ trans_choice(':count student|:count students', $this->enrollments->count(), ['count' => $this->enrollments->count()]) }}
                </h5>
                @if ($this->enrollments->isNotEmpty())
                    <span class="text-muted fs-13 me-1">{{ __('Set all to') }}</span>
                    <div class="btn-group btn-group-sm">
                        @foreach ($outcomes as $value => $label)
                            <button type="button" class="btn btn-light" wire:click="setAll('{{ $value }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($this->enrollments->isEmpty())
                <x-empty-state icon="ri-group-line" :title="__('No students to move')"
                               :message="__('Nobody in this class is still active in that academic year, or they have been promoted already.')" />
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light bg-opacity-30">
                            <tr>
                                <th scope="col" class="ps-4">{{ __('Student') }}</th>
                                <th scope="col">{{ __('Section') }}</th>
                                <th scope="col" class="pe-4" style="width: 14rem">{{ __('Outcome') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->enrollments as $enrollment)
                                @php($enrolled = in_array($enrollment->student_profile_id, $this->alreadyEnrolled, true))
                                <tr wire:key="enrollment-{{ $enrollment->id }}">
                                    <td class="ps-4">
                                        <span class="fw-medium">{{ $enrollment->student?->user?->name }}</span>
                                        <small class="d-block text-muted">{{ $enrollment->student?->admission_number }}</small>
                                    </td>
                                    <td>{{ $enrollment->section?->name ?? '—' }}</td>
                                    <td class="pe-4">
                                        @if ($enrolled)
                                            <span class="badge bg-light text-muted border">{{ __('Already enrolled in that year') }}</span>
                                        @else
                                            <select class="form-select form-select-sm" wire:model.live="decisions.{{ $enrollment->id }}" aria-label="{{ __('Outcome for :name', ['name' => $enrollment->student?->user?->name]) }}">
                                                @foreach ($outcomes as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex flex-wrap align-items-center gap-3">
                    <div class="d-flex flex-wrap gap-2 flex-grow-1 fs-13">
                        @foreach ($outcomes as $value => $label)
                            @if ($tally[$value] ?? 0)
                                <span class="badge bg-light text-body border">{{ $label }}: {{ $tally[$value] }}</span>
                            @endif
                        @endforeach
                    </div>
                    @error('to_class') <span class="text-danger fs-13">{{ $message }}</span> @enderror
                    @error('to_section') <span class="text-danger fs-13">{{ $message }}</span> @enderror
                    @error('to_year') <span class="text-danger fs-13">{{ $message }}</span> @enderror
                    <button type="button" class="btn btn-primary" wire:loading.attr="disabled" wire:target="promote"
                            x-on:click="SSMS.confirm(@js(['title' => __('Move these students on?'), 'text' => __('Each student\'s enrollment for the old year is closed and a new one is opened. This can\'t be undone in bulk.'), 'confirmText' => __('Move students'), 'cancelText' => __('Cancel')])).then(ok => ok && $wire.promote())">
                        <i class="ri-arrow-up-double-line me-1"></i>{{ __('Move students') }}
                    </button>
                </div>
            @endif
        </div>
    @endif
</div>
