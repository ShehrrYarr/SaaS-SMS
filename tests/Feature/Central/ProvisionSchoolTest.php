<?php

use App\Enums\SubscriptionState;
use App\Enums\UserType;
use App\Models\Campus;
use App\Models\Plan;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Schools\ProvisionSchool;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(Database\Seeders\PermissionCatalogSeeder::class);
    $this->plan = Plan::factory()->create(['name' => 'Standard', 'trial_days' => 14, 'price' => 99]);
});

it('provisions a ready-to-use school', function () {
    $result = app(ProvisionSchool::class)->handle(
        school: ['name' => 'Hilltop School', 'slug' => 'hilltop', 'plan_id' => $this->plan->id, 'trial_ends_at' => today()->addDays(14)->toDateString(),
            'settings' => ['currency_code' => 'PKR', 'currency_symbol' => 'Rs']],
        admin: ['name' => 'Hina Admin', 'username' => 'hina'],
        password: 'Secret123',
    );

    $school = $result->school;

    expect($school->subscriptionState())->toBe(SubscriptionState::Trial)
        ->and($school->setting('currency_symbol'))->toBe('Rs')
        ->and($result->password)->toBe('Secret123');

    tenancy()->run($school, function () use ($result) {
        expect(Role::query()->pluck('name')->all())->toContain('School Admin', 'Teacher', 'Accountant', 'Parent')
            ->and(Campus::query()->where('is_main', true)->count())->toBe(1)
            ->and($result->admin->fresh())
            ->school_id->toBe(tenant()->id)
            ->user_type->toBe(UserType::SchoolAdmin)
            ->must_change_password->toBeTrue()
            ->and(Hash::check('Secret123', $result->admin->fresh()->password))->toBeTrue()
            ->and($result->admin->fresh()->hasRole('School Admin'))->toBeTrue();
    });
});

it('lets the new administrator sign in and sends them to change the password', function () {
    app(ProvisionSchool::class)->handle(
        school: ['name' => 'Hilltop School', 'slug' => 'hilltop', 'plan_id' => $this->plan->id],
        admin: ['name' => 'Hina Admin', 'username' => 'hina'],
        password: 'Secret123',
    );

    tenancy()->set(School::query()->where('slug', 'hilltop')->first());

    Volt::test('tenant.auth.login')
        ->set('login', 'hina')
        ->set('password', 'Secret123')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->user()?->username)->toBe('hina');

    $this->get('/hilltop/admin')->assertRedirect(route('tenant.password.change', ['school' => 'hilltop']));
});

it('records the first payment when the school starts paid', function () {
    $result = app(ProvisionSchool::class)->handle(
        school: ['name' => 'Paid School', 'slug' => 'paid-school', 'plan_id' => $this->plan->id],
        admin: ['name' => 'Admin', 'username' => 'admin'],
        password: 'Secret123',
        payment: ['plan_id' => $this->plan->id, 'amount' => 99, 'period_start' => today()->toDateString(),
            'period_end' => today()->addMonth()->subDay()->toDateString(), 'paid_on' => today()->toDateString(), 'payment_method' => 'cash'],
    );

    expect($result->school->subscriptionState())->toBe(SubscriptionState::Active)
        ->and($result->school->subscription_ends_at->toDateString())->toBe(today()->addMonth()->subDay()->toDateString());
});

describe('wizard', function () {
    beforeEach(function () {
        tenancy()->enterCentral();
        $this->actingAs(makeSuperAdmin());
    });

    function fillWizard($component, array $overrides = [])
    {
        $values = [
            'name' => 'Riverside Grammar', 'email' => 'office@riverside.test', 'city' => 'Lahore',
            'admin_name' => 'Rida Khan', 'admin_username' => 'rida', ...$overrides,
        ];

        foreach ($values as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    it('suggests an address from the name until it is edited', function () {
        Volt::test('central.schools.create')
            ->set('name', 'St. Mary\'s High School')
            ->assertSet('slug', 'st-marys-high-school')
            ->set('slug', 'stmarys')
            ->set('name', 'St Marys')
            ->assertSet('slug', 'stmarys');
    });

    it('rejects reserved and taken addresses', function (string $slug) {
        makeSchool(['slug' => 'taken']);

        Volt::test('central.schools.create')
            ->set('name', 'Something')
            ->set('slug', $slug)
            ->call('submit')
            ->assertHasErrors('slug')
            ->assertSet('step', 1);
    })->with(['reserved' => 'super-admin', 'taken' => 'taken', 'malformed' => 'Bad Slug!', 'too short' => 'ab']);

    it('walks through the steps and creates the school', function () {
        $component = fillWizard(Volt::test('central.schools.create'))
            ->assertSet('plan_id', $this->plan->id)
            ->call('submit')->assertHasNoErrors()->assertSet('step', 2)
            ->call('submit')->assertHasNoErrors()->assertSet('step', 3)
            ->call('submit')->assertHasNoErrors()->assertSet('step', 4)
            ->assertSee('Riverside Grammar')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Copy these details now')
            ->assertSee('rida');

        $school = School::query()->where('slug', 'riverside-grammar')->firstOrFail();
        $password = $component->get('created')['password'];

        expect($school->subscriptionState())->toBe(SubscriptionState::Trial)
            ->and($school->trial_ends_at->toDateString())->toBe(today()->addDays(14)->toDateString())
            ->and(tenancy()->run($school, fn () => Hash::check($password, User::query()->where('username', 'rida')->value('password'))))->toBeTrue()
            ->and($component->get('admin_password'))->toBe('');
    });

    it('can start the school on a paid period', function () {
        fillWizard(Volt::test('central.schools.create'), ['billing' => 'paid', 'reference' => 'TRX-1'])
            ->set('step', 4)
            ->call('submit')
            ->assertHasNoErrors();

        $school = School::query()->where('slug', 'riverside-grammar')->firstOrFail();

        expect($school->subscriptionState())->toBe(SubscriptionState::Active)
            ->and(tenancy()->run($school, fn () => $school->subscriptionPayments()->value('reference')))->toBe('TRX-1');
    });

    it('re-checks earlier steps before creating', function () {
        fillWizard(Volt::test('central.schools.create'), ['admin_username' => 'no spaces allowed'])
            ->set('step', 4)
            ->call('submit')
            ->assertHasErrors('admin_username')
            ->assertSet('step', 3);

        expect(School::query()->where('slug', 'riverside-grammar')->exists())->toBeFalse();
    });

    it('validates the administrator', function (string $field, string $value) {
        fillWizard(Volt::test('central.schools.create'), [$field => $value])
            ->set('step', 3)
            ->call('submit')
            ->assertHasErrors($field);
    })->with([
        'missing name' => ['admin_name', ''],
        'bad username' => ['admin_username', 'a b'],
        'bad email' => ['admin_email', 'not-an-email'],
        'weak password' => ['admin_password', 'short'],
    ]);
});

it('is only for super admins', function () {
    $school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($school);

    $this->get('/super-admin/schools/create')->assertRedirect(route('central.login'));

    $this->actingAs(makeUserWithRole($school, UserType::SchoolAdmin, 'School Admin'))
        ->get('/super-admin/schools/create')
        ->assertForbidden();
});
