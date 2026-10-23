<?php

use App\Enums\UserType;
use App\Models\Plan;
use App\Services\Billing\PlanLimitExceeded;
use App\Services\Billing\PlanLimits;
use App\Support\Billing\PlanFeatures;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
});

function putOnPlan($school, ?array $features, array $attributes = []): Plan
{
    $plan = Plan::factory()->create(['features' => $features, ...$attributes]);
    $school->update(['plan_id' => $plan->id]);

    return $plan;
}

it('knows which features a permission depends on', function () {
    expect(PlanFeatures::requiredFor('payroll.generate'))->toBe(['payroll'])
        ->and(PlanFeatures::requiredFor('fees.collect'))->toBe(['finance'])
        ->and(PlanFeatures::requiredFor('portal.hr'))->toBe(['hr', 'payroll'])
        ->and(PlanFeatures::requiredFor('students.view'))->toBeNull()
        ->and(PlanFeatures::requiredFor('portal.admin'))->toBeNull()
        ->and(PlanFeatures::requiredFor('manage-schools'))->toBeNull();
});

it('withholds permissions of features the plan does not include', function () {
    putOnPlan($this->school, ['examinations']);
    $accountant = makeUserWithRole($this->school, UserType::Accountant, 'Accountant');

    tenancy()->set($this->school->fresh());

    expect($accountant->can('fees.collect'))->toBeFalse()
        ->and($accountant->can('portal.accounting'))->toBeFalse()
        ->and($accountant->can('students.view'))->toBeTrue()
        ->and($accountant->accessiblePortals())->toBe([]);
});

it('closes the portals of missing features', function () {
    putOnPlan($this->school, ['examinations']);

    $this->actingAs(makeUserWithRole($this->school, UserType::Accountant, 'Accountant'))
        ->get('/alpha/accounting')
        ->assertForbidden();
});

it('opens a portal when any of its features is included', function () {
    putOnPlan($this->school, ['payroll']);

    $this->actingAs(makeUserWithRole($this->school, UserType::Hr, 'HR'))->get('/alpha/hr')->assertOk();
});

it('grants everything on a plan with every feature, or with no plan', function (bool $withPlan) {
    if ($withPlan) {
        putOnPlan($this->school, null);
    }

    $this->actingAs(makeUserWithRole($this->school, UserType::Accountant, 'Accountant'))
        ->get('/alpha/accounting')
        ->assertOk();
})->with(['all features' => true, 'no plan' => false]);

it('hides menu items of missing features', function () {
    putOnPlan($this->school, ['examinations']);

    $this->actingAs(makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin'))
        ->get('/alpha/admin')
        ->assertOk()
        ->assertSee('/alpha/teacher', false)
        ->assertDontSee('/alpha/accounting', false)
        ->assertDontSee('/alpha/hr', false);
});

it('enforces the plan limits', function () {
    putOnPlan($this->school, null, ['max_students' => 0, 'max_staff' => null]);
    $limits = app(PlanLimits::class);
    $school = $this->school->fresh();

    expect($limits->remaining($school, 'students'))->toBe(0)
        ->and($limits->remaining($school, 'staff'))->toBeNull()
        ->and(fn () => $limits->ensureCanAdd($school, 'students'))->toThrow(PlanLimitExceeded::class);

    $limits->ensureCanAdd($school, 'staff', 500);
});
