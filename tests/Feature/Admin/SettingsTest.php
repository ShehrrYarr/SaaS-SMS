<?php

use App\Enums\UserType;
use App\Models\Activity;
use App\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha', 'name' => 'Alpha Academy']);
    $this->other = makeSchool(['slug' => 'bravo']);
    seedSchoolRoles($this->school, $this->other);
    tenancy()->set($this->school);

    $this->actingAs(makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin'));
});

it('saves regional settings, numbering and fee options', function () {
    Volt::test('tenant.admin.settings.index')
        ->assertSet('currency_code', 'USD')
        ->set('currency_code', 'PKR')
        ->set('currency_symbol', 'Rs')
        ->set('timezone', 'Asia/Karachi')
        ->set('date_format', 'd/m/Y')
        ->set('invoice_prefix', 'FEE-')
        ->set('sibling_discount_enabled', true)
        ->set('late_fee_enabled', true)
        ->set('late_fee_type', 'daily')
        ->set('late_fee_amount', '50')
        ->set('late_fee_grace_days', '5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.index'));

    $school = $this->school->fresh();

    expect($school->setting('currency_symbol'))->toBe('Rs')
        ->and($school->setting('timezone'))->toBe('Asia/Karachi')
        ->and($school->setting('date_format'))->toBe('d/m/Y')
        ->and($school->setting('numbering.invoice_prefix'))->toBe('FEE-')
        ->and($school->setting('numbering.receipt_prefix'))->toBe('RCT-')
        ->and($school->setting('fees.sibling_discount_enabled'))->toBeTrue()
        ->and($school->setting('fees.late_fee'))->toMatchArray(['enabled' => true, 'type' => 'daily', 'amount' => 50, 'grace_days' => 5, 'max_amount' => null]);
});

it('switches to the tab with the error', function () {
    Volt::test('tenant.admin.settings.index')
        ->set('tab', 'general')
        ->set('late_fee_type', 'percentage')
        ->set('late_fee_amount', '150')
        ->call('save')
        ->assertHasErrors('late_fee_amount')
        ->assertSet('tab', 'fees');
});

it('uploads and removes the logo', function () {
    Storage::fake('public');

    Volt::test('tenant.admin.settings.index')
        ->set('logo', UploadedFile::fake()->image('crest.png', 200, 200))
        ->call('save')
        ->assertHasNoErrors();

    $logo = $this->school->fresh()->logo;
    Storage::disk('public')->assertExists($logo);
    expect($logo)->toStartWith("schools/{$this->school->id}/logo-");

    Volt::test('tenant.admin.settings.index')->set('remove_logo', true)->call('save');

    Storage::disk('public')->assertMissing($logo);
    expect($this->school->fresh()->logo)->toBeNull();
});

it('rejects files that aren\'t images', function () {
    Volt::test('tenant.admin.settings.index')
        ->set('logo', UploadedFile::fake()->create('crest.svg', 5, 'image/svg+xml'))
        ->call('save')
        ->assertHasErrors('logo');
});

it('is read-only without the edit permission', function () {
    $viewer = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $viewer->givePermissionTo(['portal.admin', 'settings.view']);
    $this->actingAs(makeUserWithRole($this->school, UserType::Staff, 'Viewer'));

    Volt::test('tenant.admin.settings.index')
        ->assertSee('You can view these settings but not change them.')
        ->set('name', 'Hacked')
        ->call('save')
        ->assertForbidden();

    expect($this->school->fresh()->name)->toBe('Alpha Academy');
});

it('logs settings changes for this school only', function () {
    Volt::test('tenant.admin.settings.index')->set('name', 'Alpha Academy International')->call('save');

    expect(Activity::query()->where('description', 'School settings updated')->count())->toBe(1);

    tenancy()->set($this->other);
    expect(Activity::query()->where('description', 'School settings updated')->count())->toBe(0);
});

it('shows activity details', function () {
    Volt::test('tenant.admin.settings.index')->set('city', 'Lahore')->call('save');
    $entry = Activity::query()->latest('id')->firstOrFail();

    Volt::test('modals.activity', ['activity' => $entry->id])->assertSee('School settings updated');

    $this->get('/alpha/admin/activity-log')->assertOk()->assertSee('School settings updated');
});
