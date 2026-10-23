<?php

/*
 * Edit a school's details (Super Admin).
 *     $dispatch('open-modal', { component: 'forms.central.school', params: { school: 3 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\School;
use App\Rules\SchoolSlug;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['school' => null])->locked();

state(['name' => '', 'slug' => '', 'email' => '', 'phone' => '', 'address' => '', 'city' => '', 'country' => '']);

mount(function (int $school) {
    abort_unless(tenancy()->isCentral(), 404);
    $this->authorize('manage-schools');

    $record = School::query()->findOrFail($school);

    $this->school = $record->id;
    $this->fill($record->only(['name', 'slug', 'email', 'phone', 'address', 'city', 'country']));
});

rules(fn () => [
    'name' => ['required', 'string', 'max:150'],
    'slug' => ['required', 'string', new SchoolSlug, Rule::unique('schools', 'slug')->ignore($this->school)],
    'email' => ['nullable', 'email', 'max:255'],
    'phone' => ['nullable', 'string', 'max:30'],
    'address' => ['nullable', 'string', 'max:500'],
    'city' => ['nullable', 'string', 'max:100'],
    'country' => ['nullable', 'string', 'max:100'],
])->attributes(['slug' => __('address')]);

$save = function () {
    $this->authorize('manage-schools');
    $this->slug = Str::lower(trim($this->slug));

    $data = array_map(fn ($value) => $value === '' ? null : $value, $this->validate());
    $school = School::query()->findOrFail($this->school);
    $oldSlug = $school->slug;

    $school->update($data);

    activity('schools')->performedOn($school)->causedBy(auth()->user())
        ->withProperties(['changes' => array_keys($school->getChanges())])
        ->log('School details updated');

    $this->closeModal();
    $this->refreshTables();
    $this->toast(__('School details saved.'));

    if ($school->slug !== $oldSlug) {
        $this->dispatch('school-updated', slug: $school->slug);
    }
};

?>

<x-modal.form :title="__('Edit school')" :subtitle="__('Contact details and address. Plans and billing are managed separately.')">
    <x-form.input name="name" :label="__('School name')" required maxlength="150" />

    <x-form.input name="slug" :label="__('Address')" required maxlength="60" spellcheck="false" autocomplete="off"
                  :hint="__('Changing it changes every link to the school, including its sign-in page. Bookmarks to the old address stop working.')" />

    <div class="row">
        <div class="col-sm-6"><x-form.input name="email" type="email" :label="__('Office email')" maxlength="255" /></div>
        <div class="col-sm-6"><x-form.input name="phone" type="tel" :label="__('Office phone')" maxlength="30" /></div>
        <div class="col-sm-6"><x-form.input name="city" :label="__('City')" maxlength="100" /></div>
        <div class="col-sm-6"><x-form.input name="country" :label="__('Country')" maxlength="100" /></div>
    </div>

    <x-form.textarea name="address" :label="__('Street address')" rows="2" maxlength="500" wrapper="mb-0" />
</x-modal.form>
