<?php

use App\Models\Plan;
use App\Tables\Central\PlansTable;
use Livewire\Volt\Volt;

beforeEach(function () {
    tenancy()->enterCentral();
    $this->actingAs(makeSuperAdmin());
});

it('creates a plan with chosen features', function () {
    Volt::test('forms.central.plan')
        ->set('name', 'Growth')
        ->set('price', '79.50')
        ->set('billing_cycle', 'yearly')
        ->set('max_students', '500')
        ->set('all_features', false)
        ->set('features', ['payroll', 'finance', 'not-a-feature'])
        ->call('save')
        ->assertHasErrors('features.2')
        ->set('features', ['payroll', 'finance'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal');

    expect(Plan::query()->where('name', 'Growth')->firstOrFail())
        ->slug->toBe('growth')
        ->price->toBe('79.50')
        ->max_students->toBe(500)
        ->max_staff->toBeNull()
        // Stored in config order, whatever order they were ticked in.
        ->features->toBe(['finance', 'payroll']);
});

it('edits a plan, including switching to every feature', function () {
    $plan = Plan::factory()->features(['homework'])->create(['name' => 'Basic']);

    Volt::test('forms.central.plan', ['plan' => $plan->id])
        ->assertSet('all_features', false)
        ->assertSet('features', ['homework'])
        ->set('all_features', true)
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh())->features->toBeNull()->is_active->toBeFalse();
});

it('will not delete a plan schools are on', function () {
    $plan = Plan::factory()->create();
    makeSchool(['plan_id' => $plan->id]);
    $unused = Plan::factory()->create();

    Volt::test('components.data-table', ['table' => PlansTable::class])
        ->call('runAction', 'delete', $plan->id)
        ->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error')
        ->call('runAction', 'delete', $unused->id)
        ->assertDispatched('notify', type: 'success');

    expect(Plan::query()->find($plan->id))->not->toBeNull()
        ->and(Plan::query()->find($unused->id))->toBeNull();
});

it('only offers active plans to new schools', function () {
    Plan::factory()->create(['name' => 'Current']);
    Plan::factory()->retired()->create(['name' => 'Legacy']);

    Volt::test('central.schools.create')
        ->set('step', 2)
        ->assertSee('Current')
        ->assertDontSee('Legacy');
});
