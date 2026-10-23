<?php

use App\Enums\UserType;
use App\Notifications\SystemNotification;
use App\Support\Toast;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->actingAs($this->admin);
});

it('shows the unread count and the latest notifications', function () {
    $this->admin->notify(new SystemNotification('Fees are due', 'October invoices were issued.'));
    $this->admin->notify(new SystemNotification('Exam timetable published'));

    Volt::test('layout.notifications')
        ->assertSee('Notifications, 2 unread')
        ->assertSee('Fees are due')
        ->assertSee('October invoices were issued.')
        ->assertSee('Exam timetable published');
});

it('marks a notification read and follows its link', function () {
    $this->admin->notify(new SystemNotification('Open me', url: route('admin.dashboard')));
    $notification = $this->admin->notifications()->first();

    Volt::test('layout.notifications')
        ->call('open', $notification->id)
        ->assertRedirect(route('admin.dashboard'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('never follows links that leave the app', function () {
    $this->admin->notify(new SystemNotification('Suspicious', url: 'https://example.com/elsewhere'));

    Volt::test('layout.notifications')
        ->call('open', $this->admin->notifications()->first()->id)
        ->assertNoRedirect();
});

it('marks everything read', function () {
    $this->admin->notify(new SystemNotification('One'));
    $this->admin->notify(new SystemNotification('Two'));

    Volt::test('layout.notifications')->call('markAllRead')->assertSee('Notifications');

    expect($this->admin->unreadNotifications()->count())->toBe(0);
});

it('cannot open someone else\'s notification', function () {
    $other = makeSchoolUser($this->school);
    $other->notify(new SystemNotification('Private'));

    Volt::test('layout.notifications')->call('open', $other->notifications()->first()->id)->assertNotFound();
});

it('queues toasts for the next page', function () {
    Toast::flash('Saved.');
    Toast::flash('Two invoices were skipped.', 'warning');

    expect(Toast::pending())->toBe([
        ['type' => 'success', 'message' => 'Saved.'],
        ['type' => 'warning', 'message' => 'Two invoices were skipped.'],
    ]);
});

it('hands flashed toasts to the page that renders next', function () {
    $this->withSession(['toasts' => [['type' => 'success', 'message' => 'Settings saved.']]])
        ->get('/alpha/admin')
        ->assertSee('id="flash-toasts"', false)
        ->assertSee('Settings saved.');
});
