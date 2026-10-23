<?php

/*
 * Create / edit a department.
 *     $dispatch('open-modal', { component: 'forms.department', params: { department: 2 } })
 */

use App\Enums\UserStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campus;
use App\Models\Department;
use App\Models\StaffProfile;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['department' => null])->locked();

state(['name' => '', 'code' => '', 'campus_id' => '', 'head_staff_profile_id' => '', 'description' => '']);

mount(function (?int $department = null) {
    if (! $department) {
        $this->authorize('departments.create');

        return;
    }

    $this->authorize('departments.edit');
    $record = Department::query()->findOrFail($department);

    $this->department = $record->id;
    $this->fill([
        ...$record->only(['name', 'code', 'description']),
        'campus_id' => (string) ($record->campus_id ?? ''),
        'head_staff_profile_id' => (string) ($record->head_staff_profile_id ?? ''),
    ]);
});

$campuses = computed(fn () => Campus::query()->ordered()->pluck('name', 'id')->all());

$staff = computed(fn () => StaffProfile::query()
    ->with('user')
    ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
    ->get()
    ->sortBy(fn (StaffProfile $staff) => mb_strtolower((string) $staff->user?->name))
    ->mapWithKeys(fn (StaffProfile $staff) => [$staff->id => $staff->user?->name.($staff->designation ? " · {$staff->designation}" : '')])
    ->all());

rules(fn () => [
    'name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')->where('school_id', tenant()->id)->ignore($this->department)],
    'code' => ['nullable', 'string', 'max:20'],
    'campus_id' => ['nullable', Rule::exists('campuses', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'head_staff_profile_id' => ['nullable', Rule::exists('staff_profiles', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'description' => ['nullable', 'string', 'max:1000'],
])->attributes(['campus_id' => __('campus'), 'head_staff_profile_id' => __('head of department')]);

$save = function () {
    $this->authorize($this->department ? 'departments.edit' : 'departments.create');
    $data = array_map(fn ($value) => $value === '' ? null : $value, $this->validate());

    $this->department
        ? Department::query()->findOrFail($this->department)->update($data)
        : Department::query()->create($data);

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->department ? __('Department updated.') : __('Department created.'));
};

?>

<x-modal.form :title="$department ? __('Edit department') : __('New department')">
    <div class="row">
        <div class="col-sm-8"><x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. Science')" /></div>
        <div class="col-sm-4"><x-form.input name="code" :label="__('Code')" maxlength="20" /></div>
    </div>

    @if (count($this->campuses) > 1)
        <x-form.select name="campus_id" :label="__('Campus')" :options="$this->campuses" :placeholder="__('All campuses')" />
    @endif

    <x-form.select name="head_staff_profile_id" :label="__('Head of department')" :options="$this->staff" :placeholder="__('Not assigned')" />

    <x-form.textarea name="description" :label="__('Description')" rows="3" maxlength="1000" wrapper="mb-0" />
</x-modal.form>
