<?php

/*
 * Create / edit a campus.
 *     $dispatch('open-modal', { component: 'forms.campus', params: { campus: 2 } })
 */

use App\Enums\RecordStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['campus' => null, 'isMain' => false])->locked();

state(['name' => '', 'code' => '', 'phone' => '', 'email' => '', 'address' => '', 'status' => RecordStatus::Active->value, 'is_main' => false]);

mount(function (?int $campus = null) {
    if (! $campus) {
        $this->authorize('campuses.create');
        $this->is_main = ! Campus::query()->exists();

        return;
    }

    $this->authorize('campuses.edit');
    $record = Campus::query()->findOrFail($campus);

    $this->campus = $record->id;
    $this->isMain = $record->is_main;
    $this->fill([
        ...$record->only(['name', 'code', 'phone', 'email', 'address']),
        'status' => $record->status->value,
        'is_main' => $record->is_main,
    ]);
});

rules(fn () => [
    'name' => ['required', 'string', 'max:100', Rule::unique('campuses', 'name')->where('school_id', tenant()->id)->ignore($this->campus)],
    'code' => ['nullable', 'string', 'max:20'],
    'phone' => ['nullable', 'string', 'max:30'],
    'email' => ['nullable', 'email', 'max:255'],
    'address' => ['nullable', 'string', 'max:500'],
    'status' => ['required', Rule::enum(RecordStatus::class)],
    'is_main' => ['boolean'],
]);

$save = function () {
    $this->authorize($this->campus ? 'campuses.edit' : 'campuses.create');
    $data = array_map(fn ($value) => $value === '' ? null : $value, $this->validate());

    // The main campus stays main until another one takes over.
    $makeMain = $data['is_main'] || $this->isMain;
    unset($data['is_main']);

    DB::transaction(function () use ($data, $makeMain) {
        $campus = $this->campus
            ? tap(Campus::query()->findOrFail($this->campus))->update($data)
            : Campus::query()->create($data);

        if ($makeMain) {
            $campus->makeMain();
        }
    });

    $this->closeModal();
    $this->refreshTables();
    $this->toast($this->campus ? __('Campus updated.') : __('Campus created.'));
};

?>

<x-modal.form :title="$campus ? __('Edit campus') : __('New campus')">
    <div class="row">
        <div class="col-sm-8"><x-form.input name="name" :label="__('Name')" required maxlength="100" :placeholder="__('e.g. City Campus')" /></div>
        <div class="col-sm-4"><x-form.input name="code" :label="__('Code')" maxlength="20" /></div>
        <div class="col-sm-6"><x-form.input name="phone" type="tel" :label="__('Phone')" maxlength="30" /></div>
        <div class="col-sm-6"><x-form.input name="email" type="email" :label="__('Email')" maxlength="255" /></div>
    </div>

    <x-form.textarea name="address" :label="__('Address')" rows="2" maxlength="500" />

    <x-form.select name="status" :label="__('Status')" :options="RecordStatus::options()" :placeholder="false" required />

    @if ($isMain)
        <p class="text-muted fs-13 mb-0"><i class="ri-star-fill text-primary me-1"></i>{{ __('This is the main campus. To change that, make another campus main.') }}</p>
    @else
        <x-form.switch name="is_main" :label="__('Main campus')" wrapper="mb-0"
                       :hint="__('The default for new classes, rooms and staff.')" />
    @endif
</x-modal.form>
