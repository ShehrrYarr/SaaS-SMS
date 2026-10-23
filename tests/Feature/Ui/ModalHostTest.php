<?php

use App\Enums\UserType;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $this->actingAs(makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin'));
});

it('renders the requested form and shows the modal', function () {
    Volt::test('components.modal-host')
        ->dispatch('open-modal', component: 'forms.academic-year', size: 'lg')
        ->assertSet('component', 'forms.academic-year')
        ->assertSet('size', 'lg')
        ->assertSeeLivewire('forms.academic-year')
        ->assertDispatched('modal-show');
});

it('only opens form components', function () {
    Volt::test('components.modal-host')
        ->dispatch('open-modal', component: 'layout.user-menu')
        ->assertForbidden();
});

it('falls back to the default size for unknown sizes', function () {
    Volt::test('components.modal-host')
        ->dispatch('open-modal', component: 'forms.academic-year', size: 'gigantic')
        ->assertSet('size', 'md');
});

it('closes and clears', function () {
    Volt::test('components.modal-host')
        ->dispatch('open-modal', component: 'forms.academic-year')
        ->dispatch('close-modal')
        ->assertDispatched('modal-hide')
        ->call('clear')
        ->assertSet('component', null);
});
