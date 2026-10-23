<?php

use App\Enums\NoticeAudience;
use App\Enums\UserType;
use App\Models\Campus;
use App\Models\Notice;
use App\Models\SchoolClass;
use App\Notifications\SystemNotification;
use App\Tables\NoticesTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->grade1 = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 1']);
    $this->grade2 = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 2']);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->student1 = makeStudent($this->school, $this->grade1);
    $this->student2 = makeStudent($this->school, $this->grade2);
    $this->parent1 = makeParentOf($this->school, $this->student1);

    $this->actingAs($this->admin);
});

function writeNotice(array $values)
{
    $component = Volt::test('forms.notice');

    foreach ($values as $key => $value) {
        $component->set($key, $value);
    }

    return $component->call('save');
}

it('publishes a notice and notifies its audience', function () {
    Notification::fake();

    writeNotice(['title' => 'Sports day', 'body' => "Friday.\nBring water.", 'target_audience' => 'all'])
        ->assertHasNoErrors()
        ->assertDispatched('close-modal');

    $notice = Notice::query()->firstOrFail();

    expect($notice->isLive())->toBeTrue()
        ->and($notice->created_by)->toBe($this->admin->id)
        ->and($notice->notified_at)->not->toBeNull();

    Notification::assertSentTo([$this->teacher, $this->student1->user, $this->parent1->user], SystemNotification::class);
    Notification::assertNotSentTo($this->admin, SystemNotification::class);
});

it('narrows class notices to that class\'s students and parents', function () {
    Notification::fake();

    writeNotice(['title' => 'Grade 1 trip', 'body' => 'Zoo visit.', 'target_audience' => 'parents', 'class_id' => (string) $this->grade1->id])->assertHasNoErrors();

    Notification::assertSentTo($this->parent1->user, SystemNotification::class);
    Notification::assertNotSentTo([$this->teacher, $this->student1->user, $this->student2->user], SystemNotification::class);

    $notice = Notice::query()->firstOrFail();

    expect($notice->isVisibleTo($this->parent1->user))->toBeTrue()
        ->and($notice->isVisibleTo($this->student1->user))->toBeFalse()
        ->and($notice->isVisibleTo(makeParentOf($this->school, $this->student2)->user))->toBeFalse();
});

it('keeps drafts and scheduled notices off the board until they go live', function () {
    Notification::fake();

    writeNotice(['title' => 'Draft one', 'body' => 'Not yet.', 'publish' => 'draft'])->assertHasNoErrors();
    writeNotice(['title' => 'Later one', 'body' => 'Soon.', 'publish' => 'schedule', 'publish_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertHasNoErrors();

    $this->actingAs($this->teacher)->get('/alpha/notices')->assertOk()->assertDontSee('Draft one')->assertDontSee('Later one');
    Notification::assertNothingSent();

    // A day later the scheduler announces it.
    $this->travel(25)->hours();
    $this->artisan('notices:announce')->assertSuccessful();

    Notification::assertSentTo($this->teacher, SystemNotification::class, fn ($n) => $n->title === 'Later one');
    $this->get('/alpha/notices')->assertSee('Later one')->assertDontSee('Draft one');
});

it('rejects a schedule in the past and an expiry before publishing', function () {
    writeNotice(['title' => 'X', 'body' => 'Y', 'publish' => 'schedule', 'publish_at' => now()->subDay()->format('Y-m-d\TH:i')])->assertHasErrors('publish_at');
    writeNotice(['title' => 'X', 'body' => 'Y', 'expires_at' => now()->subHour()->format('Y-m-d\TH:i')])->assertHasErrors('expires_at');
});

it('only shows a notice to the people it is for', function () {
    $staffOnly = Notice::query()->create(['title' => 'Staff meeting', 'body' => '3pm', 'target_audience' => NoticeAudience::Staff, 'published_at' => now()->subMinute()]);

    $this->actingAs($this->teacher)->get("/alpha/notices/{$staffOnly->id}")->assertOk()->assertSee('3pm');
    $this->actingAs($this->student1->user)->get("/alpha/notices/{$staffOnly->id}")->assertNotFound();
    $this->actingAs($this->student1->user)->get('/alpha/notices')->assertDontSee('Staff meeting');
});

it('unpublishes and republishes from the table', function () {
    Notification::fake();
    $notice = Notice::query()->create(['title' => 'Old news', 'body' => '...', 'target_audience' => NoticeAudience::All, 'published_at' => now()->subDay(), 'notified_at' => now()->subDay()]);

    $table = Volt::test('components.data-table', ['table' => NoticesTable::class]);
    $table->call('runAction', 'unpublish', $notice->id);

    expect($notice->fresh()->published_at)->toBeNull();

    $table->call('runAction', 'publish', $notice->id);

    expect($notice->fresh()->isLive())->toBeTrue();
    Notification::assertSentTo($this->teacher, SystemNotification::class);
});

it('stores attachments privately and serves them only to readers', function () {
    Storage::fake('local');
    Notification::fake();

    writeNotice([
        'title' => 'Exam timetable',
        'body' => 'Attached.',
        'target_audience' => 'students',
        'attachment' => UploadedFile::fake()->create('Exam Timetable.pdf', 120, 'application/pdf'),
    ])->assertHasNoErrors();

    $notice = Notice::query()->firstOrFail();
    Storage::disk('local')->assertExists($notice->attachment);
    expect($notice->attachmentName())->toBe('exam-timetable.pdf');

    $this->actingAs($this->student1->user)->get("/alpha/notices/{$notice->id}/attachment")->assertOk()->assertDownload('exam-timetable.pdf');
    $this->actingAs($this->teacher)->get("/alpha/notices/{$notice->id}/attachment")->assertNotFound();
});

it('shows live notices on dashboards', function () {
    Notice::query()->create(['title' => 'Welcome back', 'body' => 'Term starts Monday.', 'target_audience' => NoticeAudience::All, 'published_at' => now()->subMinute(), 'is_pinned' => true]);

    $this->actingAs($this->teacher)->get('/alpha/teacher')->assertOk()->assertSee('Welcome back');
});

it('lets only notice managers write notices', function () {
    $this->actingAs($this->teacher);

    Volt::test('forms.notice')->assertForbidden();
    $this->get('/alpha/admin/notices')->assertForbidden();
});
