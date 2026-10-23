<?php

/*
 * Create / edit an examination.
 *     $dispatch('open-modal', { component: 'forms.examination' })
 *     $dispatch('open-modal', { component: 'forms.examination', params: { examination: 3 } })
 *
 * Results are published from the examination list or page, not here.
 */

use App\Enums\ExamStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Models\GradeScale;
use App\Models\Term;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, updated, uses};

uses([InteractsWithUi::class]);

state(['examination' => null, 'published' => false])->locked();

state([
    'name' => '', 'academic_year_id' => '', 'term_id' => '', 'grade_scale_id' => '',
    'start_date' => '', 'end_date' => '', 'status' => ExamStatus::Draft->value, 'description' => '',
]);

mount(function (?int $examination = null) {
    if (! $examination) {
        $this->authorize('examinations.create');

        $year = AcademicYear::query()->current()->first();
        $this->academic_year_id = (string) ($year?->id ?? '');
        $this->term_id = (string) ($year ? Term::query()->where('academic_year_id', $year->id)->current()->value('id') ?? '' : '');
        $this->grade_scale_id = (string) (GradeScale::query()->where('is_default', true)->value('id') ?? '');

        return;
    }

    $this->authorize('examinations.edit');
    $record = Examination::query()->findOrFail($examination);

    $this->examination = $record->id;
    $this->published = $record->isPublished();
    $this->fill([
        'name' => $record->name,
        'academic_year_id' => (string) $record->academic_year_id,
        'term_id' => (string) ($record->term_id ?? ''),
        'grade_scale_id' => (string) ($record->grade_scale_id ?? ''),
        'start_date' => $record->start_date->toDateString(),
        'end_date' => $record->end_date->toDateString(),
        'status' => $record->status->value,
        'description' => (string) $record->description,
    ]);
});

$years = computed(fn () => AcademicYear::query()->orderByDesc('start_date')->get());

$year = computed(fn () => $this->years->firstWhere('id', (int) $this->academic_year_id));

$terms = computed(fn () => $this->academic_year_id
    ? Term::query()->where('academic_year_id', (int) $this->academic_year_id)->orderBy('start_date')->pluck('name', 'id')->all()
    : []);

$scales = computed(fn () => GradeScale::query()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all());

$statuses = computed(fn () => collect(ExamStatus::options())->except(ExamStatus::Published->value)->all());

updated(['academic_year_id' => fn () => $this->term_id = '']);

rules(fn () => [
    'name' => ['required', 'string', 'max:100'],
    'academic_year_id' => ['required', Rule::exists('academic_years', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'term_id' => ['nullable', Rule::exists('terms', 'id')->where('academic_year_id', (int) $this->academic_year_id)->whereNull('deleted_at')],
    'grade_scale_id' => ['nullable', Rule::exists('grade_scales', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'start_date' => ['required', 'date', ...($this->year ? ['after_or_equal:'.$this->year->start_date->toDateString(), 'before_or_equal:'.$this->year->end_date->toDateString()] : [])],
    'end_date' => ['required', 'date', 'after_or_equal:start_date', ...($this->year ? ['before_or_equal:'.$this->year->end_date->toDateString()] : [])],
    'status' => [$this->published ? 'nullable' : 'required', Rule::in(array_keys($this->statuses))],
    'description' => ['nullable', 'string', 'max:1000'],
])->attributes([
    'academic_year_id' => __('academic year'),
    'term_id' => __('term'),
    'grade_scale_id' => __('grade scale'),
])->messages([
    'start_date.after_or_equal' => __('The exam must fall within the academic year.'),
    'start_date.before_or_equal' => __('The exam must fall within the academic year.'),
    'end_date.before_or_equal' => __('The exam must fall within the academic year.'),
]);

$save = function () {
    $this->authorize($this->examination ? 'examinations.edit' : 'examinations.create');
    $data = $this->validate();

    $values = [
        'name' => $data['name'],
        'academic_year_id' => $data['academic_year_id'],
        'term_id' => $data['term_id'] ?: null,
        'grade_scale_id' => $data['grade_scale_id'] ?: null,
        'start_date' => $data['start_date'],
        'end_date' => $data['end_date'],
        'description' => $data['description'] ?: null,
        ...($this->published ? [] : ['status' => $data['status']]),
    ];

    if ($this->examination) {
        $exam = Examination::query()->findOrFail($this->examination);

        if ((int) $exam->academic_year_id !== (int) $values['academic_year_id'] && $exam->schedules()->exists()) {
            $this->addError('academic_year_id', __('The date sheet is already set, so the academic year can\'t change.'));

            return;
        }

        $exam->update($values);
        $this->closeModal();
        $this->refreshTables();
        $this->toast(__('Examination updated.'));

        return;
    }

    $exam = Examination::query()->create($values);

    $this->closeModal();
    $this->flashToast(__('Examination created. Now set its date sheet.'));
    $this->redirectRoute('admin.examinations.show', ['examination' => $exam->id], navigate: true);
};

?>

<x-modal.form :title="$examination ? __('Edit examination') : __('New examination')">
    <x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. Mid-term examination')" />
    <div class="row">
        <div class="col-sm-6">
            <x-form.select name="academic_year_id" :label="__('Academic year')" required live
                           :options="$this->years->mapWithKeys(fn ($year) => [$year->id => $year->name])->all()" />
        </div>
        <div class="col-sm-6"><x-form.select name="term_id" :label="__('Term')" :options="$this->terms" :placeholder="__('Whole year')" /></div>
        <div class="col-sm-6"><x-form.input name="start_date" type="date" :label="__('Starts')" required /></div>
        <div class="col-sm-6"><x-form.input name="end_date" type="date" :label="__('Ends')" required /></div>
        <div class="col-sm-6"><x-form.select name="grade_scale_id" :label="__('Grade scale')" :options="$this->scales" :placeholder="__('School default')" /></div>
        <div class="col-sm-6">
            @if ($published)
                <label class="form-label">{{ __('Status') }}</label>
                <p class="form-control-plaintext"><span class="badge bg-success-subtle text-success">{{ __('Results published') }}</span></p>
            @else
                <x-form.select name="status" :label="__('Status')" :options="$this->statuses" :placeholder="false" required
                               :hint="__('Teachers can enter marks once it is scheduled.')" />
            @endif
        </div>
    </div>
    <x-form.textarea name="description" :label="__('Notes')" rows="2" maxlength="1000" wrapper="mb-0" />
</x-modal.form>
