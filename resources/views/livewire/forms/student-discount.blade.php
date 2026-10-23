<?php

/*
 * Give a student a discount, or change the dates of one they have.
 *     $dispatch('open-modal', { component: 'forms.student-discount' })
 *     $dispatch('open-modal', { component: 'forms.student-discount', params: { student: 12 } })
 *     $dispatch('open-modal', { component: 'forms.student-discount', params: { assignment: 4 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Livewire\Concerns\PicksStudent;
use App\Models\FeeDiscount;
use App\Models\StudentFeeDiscount;
use App\Models\StudentProfile;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class, PicksStudent::class]);

state(['assignment' => null, 'fixedStudent' => false])->locked();

state(['fee_discount_id' => '', 'valid_from' => '', 'valid_until' => '', 'remarks' => '']);

mount(function (?int $assignment = null, ?int $student = null) {
    $this->authorize('fee-discounts.manage');

    if ($assignment) {
        $record = StudentFeeDiscount::query()->with('student')->findOrFail($assignment);

        $this->assignment = $record->id;
        $this->fixedStudent = true;
        $this->setPickedStudent($record->student);
        $this->fill([
            'fee_discount_id' => (string) $record->fee_discount_id,
            'valid_from' => (string) $record->valid_from?->toDateString(),
            'valid_until' => (string) $record->valid_until?->toDateString(),
            'remarks' => (string) $record->remarks,
        ]);

        return;
    }

    if ($student) {
        $this->fixedStudent = true;
        $this->setPickedStudent(StudentProfile::query()->findOrFail($student));
    }
});

$discounts = computed(fn () => FeeDiscount::query()
    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $this->fee_discount_id))
    ->orderBy('name')
    ->get()
    ->mapWithKeys(fn (FeeDiscount $discount) => [$discount->id => $discount->name.' ('.$discount->valueLabel().')'])
    ->all());

rules(fn () => [
    'student_id' => ['required'],
    'fee_discount_id' => ['required', Rule::in(array_map('strval', array_keys($this->discounts)))],
    'valid_from' => ['nullable', 'date'],
    'valid_until' => ['nullable', 'date', ...(filled($this->valid_from) ? ['after_or_equal:valid_from'] : [])],
    'remarks' => ['nullable', 'string', 'max:255'],
])->attributes([
    'student_id' => __('student'),
    'fee_discount_id' => __('discount'),
    'valid_from' => __('start date'),
    'valid_until' => __('end date'),
]);

$save = function () {
    $this->authorize('fee-discounts.manage');
    $data = $this->validate();

    $student = $this->pickedStudent() ?? abort(404);
    $values = [
        'fee_discount_id' => (int) $data['fee_discount_id'],
        'valid_from' => $data['valid_from'] ?: null,
        'valid_until' => $data['valid_until'] ?: null,
        'remarks' => filled($data['remarks']) ? trim($data['remarks']) : null,
    ];

    $duplicate = StudentFeeDiscount::query()
        ->where('student_profile_id', $student->id)
        ->where('fee_discount_id', $values['fee_discount_id'])
        ->when($this->assignment, fn ($query) => $query->whereKeyNot($this->assignment))
        ->exists();

    if ($duplicate) {
        $this->addError('fee_discount_id', __(':name already has this discount.', ['name' => $student->user?->name]));

        return;
    }

    if ($this->assignment) {
        StudentFeeDiscount::query()->findOrFail($this->assignment)->update($values);
    } else {
        $row = StudentFeeDiscount::query()->create([...$values, 'student_profile_id' => $student->id, 'approved_by' => auth()->id()]);

        activity('student_fee_discount')->performedOn($row)->causedBy(auth()->user())
            ->withProperties(['student' => $student->user?->name, 'discount' => $this->discounts[$values['fee_discount_id']] ?? null])
            ->log('Discount given to student');
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->assignment ? __('Discount updated.') : __('Discount given to :name.', ['name' => $student->user?->name]));
};

?>

<x-modal.form :title="$assignment ? __('Edit student discount') : __('Give a discount')"
              :subtitle="__('Applies to invoices raised from now on.')">
    <x-form.student-picker :results="$this->studentResults" :selected="$studentLabel" :picked="$student_id" :search="$studentSearch" :locked="$fixedStudent" />

    <x-form.select name="fee_discount_id" :label="__('Discount')" :options="$this->discounts" required />

    <div class="row">
        <div class="col-sm-6"><x-form.input name="valid_from" type="date" :label="__('From')" :hint="__('Blank: straight away.')" /></div>
        <div class="col-sm-6"><x-form.input name="valid_until" type="date" :label="__('Until')" :hint="__('Blank: no end date.')" /></div>
    </div>
    <x-form.input name="remarks" :label="__('Remarks')" maxlength="255" wrapper="mb-0" :placeholder="__('e.g. Approved by the principal')" />
</x-modal.form>
