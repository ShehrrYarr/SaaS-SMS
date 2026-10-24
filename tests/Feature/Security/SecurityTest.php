<?php

/*
 * Attacks a visitor can try from the browser: stored scripts, tampering with
 * Livewire state, calling internals, other schools' ids and dangerous uploads.
 */

use App\Enums\UserType;
use App\Models\Campus;
use App\Models\Notice;
use App\Models\SchoolClass;
use App\Services\Auth\LoginService;
use App\Support\Uploads;
use App\Tables\StudentsTable;
use App\Tables\UsersTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;

const SCRIPT = '<script>alert("pwned")</script>';

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    $this->other = makeSchool(['slug' => 'bravo']);
    seedSchoolRoles($this->school, $this->other);
    tenancy()->set($this->school);

    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 1']);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->student = makeStudent($this->school, $this->class);

    $this->actingAs($this->admin);
});

it('escapes script payloads stored in names, notices and tables', function () {
    $victim = makeStudent($this->school, $this->class, ['name' => SCRIPT]);

    Volt::test('forms.notice')
        ->set('title', SCRIPT)
        ->set('body', SCRIPT."\nsecond line")
        ->set('target_audience', 'all')
        ->call('save')
        ->assertHasNoErrors();

    $notice = Notice::query()->firstOrFail();

    foreach ([route('admin.students.show', $victim), route('tenant.notices.show', $notice), route('tenant.notices.index'), route('admin.dashboard')] as $url) {
        $this->get($url)->assertOk()->assertDontSee(SCRIPT, false);
    }

    Volt::test('components.data-table', ['table' => StudentsTable::class])
        ->assertSee(SCRIPT)
        ->assertDontSeeHtml(SCRIPT);
});

it('refuses changes to locked component state', function () {
    expect(fn () => Volt::test('components.data-table', ['table' => StudentsTable::class])->set('table', UsersTable::class))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => Volt::test('forms.reset-user-password', ['user' => $this->teacher->id])->set('user', $this->admin->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('does not let the browser call internal helpers', function () {
    expect(fn () => Volt::test('forms.reset-user-password', ['user' => $this->teacher->id])->call('account'))
        ->toThrow(MethodNotFoundException::class)
        ->and(fn () => Volt::test('components.data-table', ['table' => StudentsTable::class])->call('toast', 'hi'))
        ->toThrow(MethodNotFoundException::class);
});

it('never opens another school\'s records by id', function () {
    $foreignUser = makeUserWithRole($this->other, UserType::Teacher, 'Teacher');
    $foreignStudent = makeStudent($this->other);

    Volt::test('forms.reset-user-password', ['user' => $foreignUser->id])->assertNotFound();
    Volt::test('forms.student-status', ['student' => $foreignStudent->id])->assertNotFound();

    $this->get(route('admin.students.show', $foreignStudent->id))->assertNotFound();
});

it('keeps students and teachers out of admin components, not just admin pages', function () {
    $this->actingAs($this->student->user);
    Volt::test('tenant.admin.settings.index')->assertForbidden();
    Volt::test('components.data-table', ['table' => StudentsTable::class])->assertForbidden();

    $this->actingAs($this->teacher);
    Volt::test('forms.reset-user-password', ['user' => $this->student->user_id])->assertForbidden();
    Volt::test('forms.notice')->assertForbidden();
});

// A real file, so Laravel detects its type from the content as it does in production.
function realUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

it('rejects scripts, pages and executables as attachments', function (string $name, string $content) {
    // The notice form's rule; the type is detected from the file's content.
    $rule = ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,webp'];

    expect(Validator::make(['attachment' => realUpload($name, $content)], ['attachment' => $rule])->fails())->toBeTrue();
})->with([
    ['page.html', '<html><body><script>alert(1)</script></body></html>'],
    ['image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    ['shell.php', '<?php system($_GET["c"]); ?>'],
    'html disguised as pdf' => ['report.pdf', '<html><script>alert(1)</script></html>'],
    ['run.exe', "MZ\x90\x00\x03"],
]);

it('never stores an uploaded image under a web-page extension on the public disk', function () {
    Storage::fake('public');

    // A genuine PNG (passes the image and mimes checks) with a script appended,
    // uploaded as "logo.html": served from /storage it would run as a page.
    $image = imagecreatetruecolor(64, 64);
    ob_start();
    imagepng($image);
    $png = ob_get_clean().'<script>alert(document.domain)</script>';

    expect(Validator::make(['logo' => realUpload('logo.html', $png)], ['logo' => ['image', 'mimes:png,jpg,jpeg,webp']])->passes())->toBeTrue();

    Volt::test('tenant.admin.settings.index')
        ->set('logo', UploadedFile::fake()->createWithContent('logo.html', $png)->mimeType('image/png'))
        ->call('save')
        ->assertHasNoErrors();

    expect($this->school->fresh()->logo)->not->toEndWith('.html');
});

it('saves uploads only under an extension the form allows', function () {
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();

    expect(Uploads::extension(realUpload('logo.html', $png), Uploads::IMAGES))->toBe('png')
        ->and(Uploads::extension(realUpload('avatar.svg', $png), Uploads::IMAGES))->toBe('png')
        ->and(Uploads::extension(realUpload('photo.JPG', $png), Uploads::IMAGES))->toBe('jpg')
        ->and(Uploads::extension(realUpload('marks.csv', "name,mark\nAli,9\n"), ['pdf', 'txt', 'csv']))->toBe('csv')
        ->and(Uploads::extension(realUpload('notes.html', 'plain words'), ['pdf', 'txt', 'csv']))->toBe('txt')
        ->and(Uploads::extension(realUpload('shell.phtml', '<?php echo 1; ?>'), ['pdf']))->toBe('bin');
});

it('sends browser hardening headers', function () {
    auth()->logout();

    $this->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect($this->get(route('home'))->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
});

it('slows down one password tried against many accounts from one address', function () {
    auth()->logout();

    foreach (range(1, LoginService::MAX_ATTEMPTS_PER_IP) as $attempt) {
        Volt::test('tenant.auth.login')->set('login', "guess{$attempt}")->set('password', 'Summer2026')->call('authenticate')
            ->assertHasErrors('login');
    }

    // Even the right password for a real account now waits.
    Volt::test('tenant.auth.login')->set('login', $this->teacher->username)->set('password', 'password')->call('authenticate')
        ->assertHasErrors('login');

    expect(auth()->check())->toBeFalse();
});

it('gives a fresh session id at sign-in', function () {
    auth()->logout();
    $this->startSession();
    $before = session()->getId();

    Volt::test('tenant.auth.login')
        ->set('login', $this->teacher->username)
        ->set('password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($this->teacher->id)
        ->and(session()->getId())->not->toBe($before);
});
