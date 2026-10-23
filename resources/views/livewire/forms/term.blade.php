<?php

/*
 * Create / edit a term inside an academic year.
 *     $dispatch('open-modal', { component: 'forms.term' })
 *     $dispatch('open-modal', { component: 'forms.term', params: { term: 3 } })
 */

use App\Enums\AcademicPeriodStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class]);

state(['term' => null])->locked();

state(['academic_year_id' => '', 'name' => '', 'start_date' => '', 'end_date' => '', 'status' => AcademicPeriodStatus::Upcoming->value]);

$years = computed(fn () => AcademicYear::query()->latest('start_date')->get());

$year = computed(fn () => $this->years->firstWhere('id', (int) $this->academic_year_id));

mount(function (?int $term = null) {
    if ($term) {
        $this->authorize('terms.edit');
        $record = Term::query()->findOrFail($term);

        $this->term = $record->id;
        $this->fill([
            'academic_year_id' => (string) $record->academic_year_id,
            'name' => $record->name,
            'start_date' => $record->start_date->toDateString(),
            'end_date' => $record->end_date->toDateString(),
            'status' => $record->status->value,
        ]);

        return;
    }

    $this->authorize('terms.create');
    $year = AcademicYear::query()->current()->first() ?? $this->years->first();
    $this->academic_year_id = (string) ($year?->id ?? '');
    $this->suggest();
});

// Suggest the next term of the chosen year: after its last term, to the year's end.
$suggest = protect(function () {
    $year = AcademicYear::query()->find($this->academic_year_id);

    if (! $year) {
        return;
    }

    $last = Term::query()->where('academic_year_id', $year->id)->latest('end_date')->first();
    $count = Term::query()->where('academic_year_id', $year->id)->count();

    $this->name = __('Term :number', ['number' => $count + 1]);
    $this->start_date = ($last ? $last->end_date->copy()->addDay() : $year->start_date)->min($year->end_date)->toDateString();
    $this->end_date = $year->end_date->toDateString();
    $this->status = $year->isCurrent() && ! Term::query()->where('academic_year_id', $year->id)->current()->exists()
        ? AcademicPeriodStatus::Current->value
        : AcademicPeriodStatus::Upcoming->value;
});

updated(['academic_year_id' => function () {
    if (! $this->term) {
        $this->suggest();
    }
}]);

rules(fn () => [
    'academic_year_id' => ['required', Rule::exists('academic_years', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'name' => ['required', 'string', 'max:50', Rule::unique('terms', 'name')->where('academic_year_id', $this->academic_year_id ?: 0)->ignore($this->term)],
    'start_date' => [
        'required', 'date',
        function (string $attribute, mixed $value, \Closure $fail) {
            if ($this->year && ($value < $this->year->start_date->toDateString() || $value > $this->year->end_date->toDateString())) {
                $fail(__('The term must fall within :year (:start – :end).', [
                    'year' => $this->year->name,
                    'start' => $this->year->start_date->format(tenant()->setting('date_format')),
                    'end' => $this->year->end_date->format(tenant()->setting('date_format')),
                ]));
            }
        },
    ],
    'end_date' => [
        'required', 'date', 'after:start_date',
        function (string $attribute, mixed $value, \Closure $fail) {
            if ($this->year && $value > $this->year->end_date->toDateString()) {
                $fail(__('The term must end by :date, when the year ends.', ['date' => $this->year->end_date->format(tenant()->setting('date_format'))]));

                return;
            }

            $overlap = Term::query()
                ->where('academic_year_id', $this->academic_year_id)
                ->when($this->term, fn ($query) => $query->whereKeyNot($this->term))
                ->whereDate('start_date', '<=', $value)
                ->whereDate('end_date', '>=', $this->start_date)
                ->first();

            if ($overlap) {
                $fail(__('These dates overlap with :name.', ['name' => $overlap->name]));
            }
        },
    ],
    'status' => ['required', Rule::enum(AcademicPeriodStatus::class)],
])->attributes([
    'academic_year_id' => __('academic year'),
    'start_date' => __('start date'),
    'end_date' => __('end date'),
]);

$save = function () {
    $this->authorize($this->term ? 'terms.edit' : 'terms.create');
    $data = $this->validate();
    $makeCurrent = $data['status'] === AcademicPeriodStatus::Current->value;

    DB::transaction(function () use ($data, $makeCurrent) {
        if ($this->term) {
            $term = tap(Term::query()->findOrFail($this->term))->update($data);
        } else {
            $data['sort_order'] = Term::query()->where('academic_year_id', $data['academic_year_id'])->count() + 1;
            $term = Term::query()->create($data);
        }

        if ($makeCurrent) {
            $term->makeCurrent();
        }
    });

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->term ? __('Term updated.') : __('Term created.'));
};

?>

<x-modal.form :title="$term ? __('Edit term') : __('New term')">
    @if ($this->years->isEmpty())
        <div class="alert alert-warning mb-0">
            {{ __('Create an academic year first.') }}
            <a href="{{ route('admin.academic-years.index') }}" wire:navigate class="alert-link">{{ __('Academic years') }}</a>
        </div>
    @else
        <x-form.select name="academic_year_id" :label="__('Academic year')" required live :placeholder="null"
                       :options="$this->years->mapWithKeys(fn ($year) => [$year->id => $year->name.($year->isCurrent() ? ' ('.__('current').')' : '')])->all()" />

        <x-form.input name="name" :label="__('Name')" required maxlength="50" :placeholder="__('e.g. Term 1, Fall semester')" />

        <div class="row">
            <div class="col-sm-6"><x-form.input name="start_date" type="date" :label="__('Start date')" required /></div>
            <div class="col-sm-6"><x-form.input name="end_date" type="date" :label="__('End date')" required /></div>
        </div>

        <x-form.select name="status" :label="__('Status')" :options="AcademicPeriodStatus::options()" :placeholder="null" required wrapper="mb-0"
                       :hint="__('Only one term per year can be current.')" />
    @endif
</x-modal.form>
