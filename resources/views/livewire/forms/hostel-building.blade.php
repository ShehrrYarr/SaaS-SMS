<?php

/*
 * Add a hostel building, or edit one.
 *     $dispatch('open-modal', { component: 'forms.hostel-building' })
 *     $dispatch('open-modal', { component: 'forms.hostel-building', params: { building: 2 } })
 */

use App\Enums\HostelType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\HostelBuilding;
use App\Models\StaffProfile;
use App\Services\Hostel\Hostels;
use App\Tables\ActionFailed;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['building' => null])->locked();

state(['name' => '', 'type' => HostelType::Mixed->value, 'warden_staff_profile_id' => '', 'address' => '', 'description' => '']);

mount(function (?int $building = null) {
    $this->authorize('hostel.manage');

    if ($building) {
        $record = HostelBuilding::query()->findOrFail($building);

        $this->building = $record->id;
        $this->fill([
            ...array_map(fn ($value) => (string) $value, $record->only(['name', 'address', 'description'])),
            'type' => $record->type->value,
            'warden_staff_profile_id' => (string) ($record->warden_staff_profile_id ?? ''),
        ]);
    }
});

$staff = computed(fn () => StaffProfile::query()
    ->with('user')
    ->whereHas('user')
    ->get()
    ->sortBy(fn (StaffProfile $staff) => mb_strtolower($staff->user->name))
    ->mapWithKeys(fn (StaffProfile $staff) => [$staff->id => $staff->user->name.($staff->designation ? ' · '.$staff->designation : '')])
    ->all());

rules(fn () => [
    'name' => [
        'required', 'string', 'max:100',
        Rule::unique('hostel_buildings', 'name')->where('school_id', tenant()->id)->when(! $this->building, fn ($rule) => $rule->whereNull('deleted_at'))->ignore($this->building),
    ],
    'type' => ['required', Rule::enum(HostelType::class)],
    'warden_staff_profile_id' => ['nullable', Rule::in(array_map('strval', array_keys($this->staff)))],
    'address' => ['nullable', 'string', 'max:500'],
    'description' => ['nullable', 'string', 'max:1000'],
])->attributes(['warden_staff_profile_id' => __('warden')]);

$save = function (Hostels $hostels) {
    $this->authorize('hostel.manage');
    $data = $this->validate();

    try {
        $record = $hostels->saveBuilding($data, $this->building ? HostelBuilding::query()->findOrFail($this->building) : null);
    } catch (ActionFailed $e) {
        $this->addError('type', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('hostel-updated');
    $this->toast($this->building ? __('Building updated.') : __(':name added.', ['name' => $record->name]));
};

?>

<x-modal.form :title="$building ? __('Edit building') : __('Add a hostel building')">
    <div class="row">
        <div class="col-sm-7"><x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. Iqbal House')" /></div>
        <div class="col-sm-5"><x-form.select name="type" :label="__('For')" :options="HostelType::options()" :placeholder="false" required /></div>
    </div>
    <x-form.select name="warden_staff_profile_id" :label="__('Warden')" :options="$this->staff" :placeholder="__('No warden yet')"
                   :hint="__('Their name and phone are shown to residents\' parents.')" />
    <x-form.textarea name="address" :label="__('Address')" rows="2" maxlength="500" />
    <x-form.textarea name="description" :label="__('Notes')" rows="2" maxlength="1000" wrapper="mb-0" />
</x-modal.form>
