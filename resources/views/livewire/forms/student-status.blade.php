<?php

/*
 * Mark a student as left, transferred, graduated or suspended, or take them back.
 *     $dispatch('open-modal', { component: 'forms.student-status', params: { student: 7 } })
 */

use App\Enums\StudentStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\StudentProfile;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['student' => null, 'name' => '', 'current' => ''])->locked();

state(['status' => '', 'on' => '', 'remarks' => '']);

mount(function (int $student) {
    $this->authorize('students.edit');

    $record = StudentProfile::query()->with('user')->findOrFail($student);

    $this->student = $record->id;
    $this->name = (string) $record->user?->name;
    $this->current = $record->status->value;
    $this->status = $record->status->value;
    $this->on = tenant()->today()->toDateString();
});

rules(fn () => [
    'status' => ['required', Rule::enum(StudentStatus::class)],
    'on' => ['nullable', 'date'],
    'remarks' => ['nullable', 'string', 'max:255'],
]);

$save = function (StudentRecords $records) {
    $this->authorize('students.edit');
    $data = $this->validate();

    try {
        $records->changeStatus(
            StudentProfile::query()->with('user')->findOrFail($this->student),
            StudentStatus::from($data['status']),
            filled($data['on']) ? Carbon::parse($data['on']) : null,
            $data['remarks'] ?: null,
            auth()->user(),
        );
    } catch (ActionFailed $e) {
        $this->addError('status', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__('Status of :name updated.', ['name' => $this->name]));
};

?>

@php($leaving = in_array($status, [StudentStatus::Left->value, StudentStatus::Transferred->value, StudentStatus::Graduated->value], true))

<x-modal.form :title="__('Change status')" :subtitle="$name">
    <x-form.select name="status" :label="__('Status')" :options="StudentStatus::options()" :placeholder="false" required live />

    @if ($leaving)
        <x-form.input name="on" type="date" :label="__('Effective date')" />
    @endif

    <x-form.input name="remarks" :label="__('Remarks')" maxlength="255" :placeholder="__('e.g. Moved to another city')" wrapper="mb-3" />

    @if ($status !== $current)
        <div class="alert alert-light border fs-13 mb-0">
            @if ($status === StudentStatus::Active->value)
                {{ __('The student can sign in again and counts towards the plan\'s student limit.') }}
            @elseif ($status === StudentStatus::Suspended->value)
                {{ __('The student stays enrolled but can\'t sign in.') }}
            @else
                {{ __('The student leaves the class register and can\'t sign in. Their records are kept.') }}
            @endif
        </div>
    @endif
</x-modal.form>
