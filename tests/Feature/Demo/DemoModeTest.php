<?php

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\DemoLocked;
use App\Models\User;
use App\Support\Demo;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

beforeEach(function () {
    config(['demo.enabled' => true, 'demo.school' => 'greenfield', 'demo.password' => 'password']);

    $this->demo = makeSchool(['slug' => 'greenfield', 'name' => 'Greenfield Academy']);
    $this->other = makeSchool(['slug' => 'riverside']);
    seedSchoolRoles($this->demo, $this->other);

    $this->admin = makeUserWithRole($this->demo, UserType::SchoolAdmin, 'School Admin', ['username' => 'admin', 'password' => 'password']);
    $this->teacher = makeUserWithRole($this->demo, UserType::Teacher, 'Teacher', ['username' => 'sana.malik', 'password' => 'password']);
});

afterEach(fn () => Demo::enforceLocks(null));

describe('landing page', function () {
    it('shows the demo roles, plans and school finder, with no console sign-in', function () {
        $this->seed(PlanSeeder::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Try every role in one click')
            ->assertSee('Greenfield Academy')
            ->assertSee(route('demo.enter', 'teacher'))
            ->assertSee('Premium')
            ->assertSee('Already using')
            ->assertDontSee(route('central.login'));
    });

    it('leaves the demo out when it is switched off', function () {
        config(['demo.enabled' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Try every role in one click')
            ->assertSee('Already using');
    });

    it('still takes people to their school\'s sign-in page', function () {
        Volt::test('welcome')
            ->set('school', 'riverside')
            ->call('go')
            ->assertRedirect(route('tenant.login', ['school' => 'riverside']));
    });
});

describe('one-click sign-in', function () {
    it('signs the visitor in as the chosen demo account', function () {
        $this->post(route('demo.enter', 'teacher'))
            ->assertRedirect(tenancy()->run($this->demo, fn () => route('teacher.dashboard')));

        $this->assertAuthenticatedAs($this->teacher);
    });

    it('switches accounts when the visitor picks another role', function () {
        $this->post(route('demo.enter', 'teacher'));
        $this->post(route('demo.enter', 'admin'))
            ->assertRedirect(tenancy()->run($this->demo, fn () => route('admin.dashboard')));

        $this->assertAuthenticatedAs($this->admin);
    });

    it('is not there when the demo is off or the role is unknown', function () {
        $this->post(route('demo.enter', 'principal'))->assertNotFound();

        config(['demo.enabled' => false]);

        $this->post(route('demo.enter', 'admin'))->assertNotFound();
        $this->assertGuest();
    });

    it('asks the visitor to come back while the demo is being rebuilt', function () {
        config(['demo.school' => 'oakridge']);

        $this->post(route('demo.enter', 'admin'))
            ->assertRedirect(route('home').'#demo')
            ->assertSessionHas('demo-unavailable');

        $this->assertGuest();
    });
});

describe('locks', function () {
    beforeEach(fn () => Demo::enforceLocks(true));

    it('keeps every sign-in working', function () {
        tenancy()->run($this->demo, function () {
            $teacher = User::query()->findOrFail($this->teacher->id);

            expect(fn () => $teacher->update(['password' => 'something-else1']))->toThrow(DemoLocked::class)
                ->and(fn () => $teacher->fresh()->update(['username' => 'sana']))->toThrow(DemoLocked::class)
                ->and(fn () => $teacher->fresh()->update(['status' => UserStatus::Inactive]))->toThrow(DemoLocked::class)
                ->and(fn () => $teacher->fresh()->delete())->toThrow(DemoLocked::class);

            // A rehash of the demo password and other profile changes are fine.
            $teacher->fresh()->update(['password' => 'password', 'name' => 'Sana M.']);

            // Accounts visitors add themselves can be managed as usual.
            $added = makeSchoolUser($this->demo, UserType::Staff, ['username' => 'new.clerk']);
            $added->update(['status' => UserStatus::Inactive]);

            expect($teacher->fresh()->name)->toBe('Sana M.')
                ->and($added->fresh()->status)->toBe(UserStatus::Inactive);
        });
    });

    it('keeps the school\'s name and settings', function () {
        tenancy()->set($this->demo);
        $this->actingAs($this->admin);

        Volt::test('tenant.admin.settings.index')
            ->assertSet('canEdit', false)
            ->assertSee('can\'t be changed')
            ->set('name', 'Hacked School')
            ->call('save')
            ->assertDispatched('notify', type: 'warning');

        expect($this->demo->fresh()->name)->toBe('Greenfield Academy')
            ->and(fn () => $this->demo->update(['logo' => 'x.png']))->toThrow(DemoLocked::class);
    });

    it('turns a blocked password change into a warning', function () {
        tenancy()->set($this->demo);
        $this->actingAs($this->admin);

        Volt::test('tenant.auth.change-password')
            ->assertSee('can\'t be changed in the demo')
            ->set('current_password', 'password')
            ->set('password', 'newpass123')
            ->set('password_confirmation', 'newpass123')
            ->call('save')
            ->assertDispatched('notify', type: 'warning')
            ->assertNoRedirect();

        expect(Hash::check('password', $this->admin->fresh()->password))->toBeTrue();
    });

    it('leaves other schools and the console alone', function () {
        $user = makeUserWithRole($this->other, UserType::Teacher, 'Teacher', ['username' => 'admin']);

        tenancy()->run($this->other, fn () => $user->update(['username' => 'renamed', 'password' => 'another-pass1']));
        $this->other->update(['name' => 'Riverside Renamed']);

        Demo::enforceLocks(null);
        tenancy()->run($this->demo, fn () => User::query()->findOrFail($this->teacher->id)->update(['username' => 'rebuilt']));

        expect($user->fresh()->username)->toBe('renamed')
            ->and($this->other->fresh()->name)->toBe('Riverside Renamed')
            ->and($this->teacher->fresh()->username)->toBe('rebuilt');
    });
});
