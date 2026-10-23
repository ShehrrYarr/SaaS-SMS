<?php

use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\ParentProfile;
use App\Models\SchoolClass;
use App\Models\StaffProfile;
use App\Models\StudentEnrollment;
use App\Models\StudentProfile;
use App\Services\Imports\StaffImport;
use App\Services\Imports\StudentImport;
use App\Services\People\CredentialSheet;
use App\Services\Students\PromoteStudents;
use App\Services\Students\StudentRecords;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->year = AcademicYear::factory()->current()->create(['name' => '2026-2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->one = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 1', 'numeric_level' => 1]);
    $this->oneA = $this->one->sections()->create(['name' => 'A']);
    $this->two = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 2', 'numeric_level' => 2, 'code' => 'G2']);

    $this->actingAs($this->admin);
});

function csvFile(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    $handle = fopen($path, 'w');

    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }

    fclose($handle);

    return $path;
}

it('checks every student row and explains the problems', function () {
    app(StudentRecords::class)->admit(['name' => 'Existing', 'username' => 'taken.name', 'phone' => '0999', 'class_id' => $this->one->id, 'section_id' => $this->oneA->id]);

    $path = csvFile([
        ['Name', 'Class', 'Section', 'Username', 'Guardian Name', 'Guardian Phone', 'DOB'],
        ['Good One', 'Grade 1', 'a', '', 'Dad', '0300', '2019-05-01'],
        ['', 'Grade 9', '', '', '', '', ''],
        ['No Section', 'Grade 1', '', 'taken.name', '', '', ''],
        ['Bad Guardian', 'g2', 'X', '', 'Mum', '0999', '01/13/2019'],
    ]);

    $result = app(StudentImport::class)->check($path);

    expect($result['valid'])->toBe(1)->and($result['invalid'])->toBe(3);

    $errors = collect($result['rows'])->pluck('errors', 'line');
    expect(implode(' ', $errors[3]))->toContain('Name is missing')->toContain('no class called Grade 9')
        ->and(implode(' ', $errors[4]))->toContain('say which one')->toContain('already used')
        ->and(implode(' ', $errors[5]))->toContain('no sections')->toContain('belongs to a student')->toContain('isn\'t a date');
});

it('imports students and links siblings to one guardian account', function () {
    $path = csvFile([
        ['name', 'class', 'section', 'gender', 'guardian_name', 'guardian_relation', 'guardian_phone'],
        ['Sara Khan', 'Grade 1', 'A', 'f', 'Imran Khan', 'father', '0300'],
        ['Ali Khan', 'Grade 1', 'A', 'male', 'Imran Khan', 'father', '0300'],
        ['Zoya', 'G2', '', 'female', '', '', ''],
    ]);

    $importer = app(StudentImport::class);
    $check = $importer->check($path);

    expect($check['invalid'])->toBe(0)
        ->and($check['rows'][1]['notes'][0])->toContain('Same guardian as line 2');

    $result = $importer->import(collect($check['rows'])->pluck('data')->all(), $this->admin);

    expect($result->imported)->toBe(3)
        ->and($result->credentials)->toHaveCount(4) // three students, one parent
        ->and(StudentProfile::query()->count())->toBe(3)
        ->and(StudentEnrollment::query()->where('academic_year_id', $this->year->id)->count())->toBe(3)
        ->and(ParentProfile::query()->sole()->children()->count())->toBe(2);
});

it('runs the import wizard and offers the sign-in sheet once imported', function () {
    $csv = "name,class,section\nWizard Kid,Grade 1,A\n,Grade 1,A\n";

    $wizard = Volt::test('imports.wizard', ['kind' => 'students'])
        ->set('file', UploadedFile::fake()->createWithContent('students.csv', $csv))
        ->call('check')
        ->assertHasNoErrors()
        ->assertSet('step', 'review')
        ->assertSet('summary.valid', 1)
        ->assertSet('summary.invalid', 1)
        ->assertSee('Name is missing')
        ->call('import')
        ->assertSet('step', 'done')
        ->assertSet('imported', 1);

    expect(StudentProfile::query()->count())->toBe(1);

    $token = $wizard->get('sheet');
    $response = app(CredentialSheet::class)->download($token, 'sheet.csv');

    ob_start();
    $response->sendContent();
    $sheet = ob_get_clean();

    expect($sheet)->toContain('wizard.kid')->toContain('Temporary password');
});

it('refuses a file without the required columns', function () {
    Volt::test('imports.wizard', ['kind' => 'students'])
        ->set('file', UploadedFile::fake()->createWithContent('students.csv', "first,last\nA,B\n"))
        ->call('check')
        ->assertHasErrors('file')
        ->assertSet('step', 'upload');
});

it('imports staff with their built-in roles', function () {
    $path = csvFile([
        ['name', 'type', 'designation', 'salary', 'joining_date'],
        ['Tariq Aziz', 'teacher', 'Teacher', '45,000', '2026-08-01'],
        ['Hina HR', 'hr', 'HR Officer', '', ''],
        ['Nobody', 'pilot', '', 'abc', 'yesterday'],
    ]);

    $importer = app(StaffImport::class);
    $check = $importer->check($path);

    expect($check['valid'])->toBe(2)
        ->and(implode(' ', $check['rows'][2]['errors']))->toContain('isn\'t one of')->toContain('valid amount')->toContain('isn\'t a date');

    $importer->import(collect($check['rows'])->filter(fn ($row) => ! $row['errors'])->pluck('data')->all(), $this->admin);

    $tariq = StaffProfile::query()->whereHas('user', fn ($query) => $query->where('name', 'Tariq Aziz'))->with('user')->sole();
    expect((float) $tariq->salary)->toBe(45000.0)
        ->and($tariq->user->hasRole('Teacher'))->toBeTrue()
        ->and(StaffProfile::query()->count())->toBe(2);
});

it('promotes a class into the next year, with repeaters and leavers', function () {
    $next = AcademicYear::factory()->create(['name' => '2027-2028', 'start_date' => '2027-08-01', 'end_date' => '2028-06-30']);
    $records = app(StudentRecords::class);

    $up = $records->admit(['name' => 'Up', 'class_id' => $this->one->id, 'section_id' => $this->oneA->id, 'roll_number' => '1'])->student;
    $stay = $records->admit(['name' => 'Stay', 'class_id' => $this->one->id, 'section_id' => $this->oneA->id])->student;
    $leave = $records->admit(['name' => 'Leave', 'class_id' => $this->one->id, 'section_id' => $this->oneA->id])->student;

    $enrollment = fn (StudentProfile $student) => $student->enrollments()->where('academic_year_id', $this->year->id)->value('id');

    $counts = app(PromoteStudents::class)->handle($this->year, $next, $this->two, null, [
        $enrollment($up) => PromoteStudents::PROMOTE,
        $enrollment($stay) => PromoteStudents::RETAIN,
        $enrollment($leave) => PromoteStudents::LEAVE,
    ], $this->admin);

    expect($counts)->toMatchArray(['promote' => 1, 'retain' => 1, 'leave' => 1, 'skipped' => 0]);

    $up->refresh();
    expect($up->class_id)->toBe($this->two->id)
        ->and($up->roll_number)->toBeNull()
        ->and($up->enrollments()->where('academic_year_id', $this->year->id)->value('status'))->toBe(EnrollmentStatus::Promoted)
        ->and($up->enrollments()->where('academic_year_id', $next->id)->value('class_id'))->toBe($this->two->id)
        ->and($stay->enrollments()->where('academic_year_id', $next->id)->value('section_id'))->toBe($this->oneA->id)
        ->and($leave->fresh()->status)->toBe(StudentStatus::Left)
        ->and($leave->fresh()->user->status)->toBe(UserStatus::Inactive);

    // Running it again changes nothing: they are no longer active in the old year.
    $again = app(PromoteStudents::class)->handle($this->year, $next, $this->two, null, [$enrollment($up) => PromoteStudents::PROMOTE]);
    expect($again['promote'])->toBe(0);
});

it('runs promotion from the page', function () {
    $previous = AcademicYear::factory()->archived()->create(['name' => '2025-2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30']);
    $student = app(StudentRecords::class)->admit(['name' => 'Last Year', 'class_id' => $this->one->id, 'section_id' => $this->oneA->id])->student;

    // Pretend they were enrolled last year and not yet this year.
    $student->enrollments()->update(['academic_year_id' => $previous->id]);

    Volt::test('tenant.admin.students.promote')
        ->assertSet('from_year', (string) $previous->id)
        ->assertSet('to_year', (string) $this->year->id)
        ->set('from_class', (string) $this->one->id)
        ->assertSet('to_class', (string) $this->two->id)
        ->assertSee('Last Year')
        ->call('promote')
        ->assertHasNoErrors()
        ->assertDispatched('notify', type: 'success');

    expect($student->fresh()->class_id)->toBe($this->two->id)
        ->and($student->enrollments()->where('academic_year_id', $this->year->id)->exists())->toBeTrue();
});
