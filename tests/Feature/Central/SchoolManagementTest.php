<?php

use App\Enums\SchoolStatus;
use App\Enums\SubscriptionState;
use App\Enums\UserType;
use App\Models\Plan;
use App\Models\School;
use App\Models\User;
use App\Tables\Central\SchoolsTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->plan = Plan::factory()->create(['name' => 'Standard', 'max_students' => 300]);
    $this->school = makeSchool(['name' => 'Alpha Academy', 'slug' => 'alpha', 'plan_id' => $this->plan->id, 'subscription_ends_at' => today()->addMonth()]);
    $this->other = makeSchool(['name' => 'Bravo College', 'slug' => 'bravo', 'trial_ends_at' => today()->addDays(5)]);
    seedSchoolRoles($this->school, $this->other);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin', ['username' => 'head', 'password' => 'old-pass-1']);

    tenancy()->enterCentral();
    $this->actingAs($this->superAdmin = makeSuperAdmin());
});

function schoolsTable()
{
    return Volt::test('components.data-table', ['table' => SchoolsTable::class]);
}

it('lists every school', function () {
    schoolsTable()
        ->assertSee('Alpha Academy')
        ->assertSee('Bravo College')
        ->assertSee('/alpha');
});

it('filters by subscription state', function () {
    schoolsTable()
        ->set('filters.subscription', SubscriptionState::Trial->value)
        ->assertSee('Bravo College')
        ->assertDontSee('Alpha Academy');
});

it('suspends a school with a reason, which closes its portals', function () {
    Volt::test('forms.central.suspend-school', ['school' => $this->school->id])
        ->call('save')
        ->assertHasErrors('reason')
        ->set('reason', 'Payment overdue')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal');

    expect($this->school->fresh())
        ->status->toBe(SchoolStatus::Suspended)
        ->suspension_reason->toBe('Payment overdue');

    auth()->logout();
    $this->get('/alpha/login')->assertForbidden()->assertSee('suspended');
});

it('reactivates a suspended school', function () {
    $this->school->update(['status' => SchoolStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => 'Test']);

    schoolsTable()
        ->call('runAction', 'reactivate', $this->school->id)
        ->assertDispatched('notify', type: 'success');

    expect($this->school->fresh()->status)->toBe(SchoolStatus::Active);
});

it('will not reactivate a school whose subscription has run out', function () {
    $this->school->update(['status' => SchoolStatus::Suspended, 'subscription_ends_at' => today()->subMonths(2)]);

    schoolsTable()
        ->call('runAction', 'reactivate', $this->school->id)
        ->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error' && str_contains($params['message'], 'Record a payment'));

    expect($this->school->fresh()->status)->toBe(SchoolStatus::Suspended);
});

it('edits the details and follows an address change', function () {
    Volt::test('forms.central.school', ['school' => $this->school->id])
        ->assertSet('name', 'Alpha Academy')
        ->set('slug', 'bravo')
        ->call('save')
        ->assertHasErrors('slug')
        ->set('slug', 'alpha-academy')
        ->set('city', 'Karachi')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('school-updated', slug: 'alpha-academy');

    expect($this->school->fresh())->slug->toBe('alpha-academy')->city->toBe('Karachi');
});

it('changes the plan and warns about limits the school is over', function () {
    $tiny = Plan::factory()->create(['name' => 'Tiny', 'max_staff' => 1]);
    $clerk = makeSchoolUser($this->school);

    foreach ([$this->admin, $clerk] as $i => $user) {
        DB::table('staff_profiles')->insert([
            'school_id' => $this->school->id, 'user_id' => $user->id, 'employee_id' => "E{$i}", 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    Volt::test('forms.central.school-subscription', ['school' => $this->school->id])
        ->assertDontSee('this plan allows')
        ->set('plan_id', (string) $tiny->id)
        ->assertSee('The school has 2 staff; this plan allows 1.')
        ->set('trial_ends_at', today()->addDays(30)->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $school = $this->school->fresh();

    expect($school->plan_id)->toBe($tiny->id)
        ->and($school->trial_ends_at->toDateString())->toBe(today()->addDays(30)->toDateString());
});

it('records a payment and reactivates the school', function () {
    $this->school->update(['status' => SchoolStatus::Suspended, 'subscription_ends_at' => today()->subMonths(2)]);

    Volt::test('forms.central.subscription-payment', ['school' => $this->school->id])
        ->assertSet('amount', '100.00')
        ->assertSet('period_start', today()->toDateString())
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('refresh-tables');

    expect($this->school->fresh())
        ->status->toBe(SchoolStatus::Active)
        ->subscription_ends_at->toDateString()->toBe(today()->addMonth()->subDay()->toDateString());
});

it('picks the school when recording a payment from the subscriptions page', function () {
    Volt::test('forms.central.subscription-payment')
        ->call('save')
        ->assertHasErrors('school_id')
        ->set('school_id', (string) $this->other->id)
        ->set('amount', '50')
        ->set('period_end', today()->addMonth()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    expect($this->other->fresh()->subscription_ends_at->toDateString())->toBe(today()->addMonth()->toDateString());
});

it('resets an administrator\'s password and signs them out everywhere', function () {
    // Tests run on the array driver; sessions only live in a table in production.
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => time()]);

    $component = Volt::test('forms.central.reset-password', ['user' => $this->admin->id])
        ->set('password', 'New-pass-42')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('done', true)
        ->assertSee('New-pass-42');

    $admin = User::query()->find($this->admin->id);

    expect(Hash::check('New-pass-42', $admin->password))->toBeTrue()
        ->and($admin->must_change_password)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $admin->id)->exists())->toBeFalse();

    $component->call('save')->assertDispatched('close-modal');
});

it('only resets school administrators', function () {
    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');

    Volt::test('forms.central.reset-password', ['user' => $teacher->id])->assertNotFound();
    Volt::test('forms.central.reset-password', ['user' => $this->superAdmin->id])->assertNotFound();
});

it('adds another administrator to a school', function () {
    Volt::test('forms.central.school-admin', ['school' => $this->school->id])
        ->set('name', 'Deputy Head')
        ->set('username', 'head')
        ->call('save')
        ->assertHasErrors('username')
        ->set('username', 'deputy')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('done', true);

    $deputy = User::query()->where('username', 'deputy')->firstOrFail();

    expect($deputy->school_id)->toBe($this->school->id)
        ->and($deputy->must_change_password)->toBeTrue()
        ->and(tenancy()->run($this->school, fn () => $deputy->hasRole('School Admin')))->toBeTrue();
});

it('allows the same administrator username at another school', function () {
    Volt::test('forms.central.school-admin', ['school' => $this->other->id])
        ->set('name', 'Bravo Head')
        ->set('username', 'head')
        ->call('save')
        ->assertHasNoErrors();
});

it('deletes only suspended schools', function () {
    Volt::test('central.schools.show', ['school' => $this->school])
        ->call('delete')
        ->assertDispatched('notify', type: 'error');

    expect(School::query()->find($this->school->id))->not->toBeNull();

    $this->school->update(['status' => SchoolStatus::Suspended]);

    Volt::test('central.schools.show', ['school' => $this->school->fresh()])
        ->call('delete')
        ->assertRedirect(route('central.schools.index'));

    expect(School::query()->find($this->school->id))->toBeNull()
        ->and(School::withTrashed()->find($this->school->id))->not->toBeNull();
});

it('serves every console page', function (string $url, string $text) {
    $this->get($url)->assertOk()->assertSee($text);
})->with([
    'dashboard' => ['/super-admin', 'Platform overview'],
    'schools' => ['/super-admin/schools', 'New school'],
    'school' => ['/super-admin/schools/alpha', 'School administrators'],
    'wizard' => ['/super-admin/schools/create', 'About the school'],
    'plans' => ['/super-admin/plans', 'New plan'],
    'subscriptions' => ['/super-admin/subscriptions', 'Payment history'],
    'analytics' => ['/super-admin/analytics', 'Monthly recurring revenue'],
]);

it('keeps school users out of the console', function () {
    $this->actingAs($this->admin)->get('/super-admin/schools')->assertForbidden();
});

it('refuses console forms outside the console', function () {
    tenancy()->set($this->school);

    Volt::test('forms.central.school', ['school' => $this->school->id])->assertNotFound();
});
