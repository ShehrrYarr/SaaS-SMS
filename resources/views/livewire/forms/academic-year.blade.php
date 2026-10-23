<?php

/*
 * Create / edit an academic year (opened in the modal host).
 *     $dispatch('open-modal', { component: 'forms.academic-year' })
 *     $dispatch('open-modal', { component: 'forms.academic-year', params: { academicYear: 3 } })
 */

use App\Enums\AcademicPeriodStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\AcademicYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['academicYear' => null])->locked();

state(['name' => '', 'start_date' => '', 'end_date' => '', 'status' => AcademicPeriodStatus::Upcoming->value]);

mount(function (?int $academicYear = null) {
    if ($academicYear) {
        $this->authorize('academic-years.edit');

        $year = AcademicYear::query()->findOrFail($academicYear);

        $this->academicYear = $year->id;
        $this->name = $year->name;
        $this->start_date = $year->start_date->toDateString();
        $this->end_date = $year->end_date->toDateString();
        $this->status = $year->status->value;

        return;
    }

    $this->authorize('academic-years.create');

    // Suggest the year after the latest one (or the coming school year).
    $latest = AcademicYear::query()->latest('end_date')->first();
    $start = $latest ? $latest->end_date->copy()->addDay() : Carbon::create(now()->month >= 8 ? now()->year : now()->year - 1, 8, 1);
    $end = $start->copy()->addYear()->subDay();

    $this->name = $start->year.'-'.$end->year;
    $this->start_date = $start->toDateString();
    $this->end_date = $end->toDateString();
    $this->status = AcademicYear::query()->current()->exists()
        ? AcademicPeriodStatus::Upcoming->value
        : AcademicPeriodStatus::Current->value;
});

rules(fn () => [
    'name' => [
        'required', 'string', 'max:50',
        Rule::unique('academic_years', 'name')->where('school_id', tenant()->id)->ignore($this->academicYear),
    ],
    'start_date' => ['required', 'date'],
    'end_date' => [
        'required', 'date', 'after:start_date',
        function (string $attribute, mixed $value, \Closure $fail) {
            $overlap = AcademicYear::query()
                ->when($this->academicYear, fn ($query) => $query->whereKeyNot($this->academicYear))
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
    'start_date' => __('start date'),
    'end_date' => __('end date'),
]);

$save = function () {
    $this->authorize($this->academicYear ? 'academic-years.edit' : 'academic-years.create');

    $data = $this->validate();
    $makeCurrent = $data['status'] === AcademicPeriodStatus::Current->value;

    DB::transaction(function () use ($data, $makeCurrent) {
        $year = $this->academicYear
            ? tap(AcademicYear::query()->findOrFail($this->academicYear))->update($data)
            : AcademicYear::query()->create($data);

        if ($makeCurrent) {
            $year->makeCurrent();
        }
    });

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->academicYear ? __('Academic year updated.') : __('Academic year created.'));
};

?>

<x-modal.form :title="$academicYear ? __('Edit academic year') : __('New academic year')"
              :subtitle="__('A school year, e.g. 2026-2027. Terms and exams are organised under it.')">
    <x-form.input name="name" :label="__('Name')" :placeholder="__('e.g. 2026-2027')" required maxlength="50" />

    <div class="row">
        <div class="col-sm-6">
            <x-form.input name="start_date" type="date" :label="__('Start date')" required />
        </div>
        <div class="col-sm-6">
            <x-form.input name="end_date" type="date" :label="__('End date')" required />
        </div>
    </div>

    <x-form.select name="status" :label="__('Status')" :options="AcademicPeriodStatus::options()" :placeholder="null" required
                   :hint="__('Making a year current archives the year that is current now.')" wrapper="mb-0" />
</x-modal.form>
