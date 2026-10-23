<?php

namespace App\Services\Imports;

use App\Enums\GuardianRelation;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Services\People\Accounts;
use App\Services\Students\Guardians;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Students from CSV, each with an optional guardian. Guardians are matched
 * to existing accounts by phone or email, so siblings in the same file (or
 * already in the school) share one parent account.
 */
class StudentImport extends RowImport
{
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** @var Collection<int, SchoolClass>|null */
    private ?Collection $classes = null;

    public function __construct(
        Accounts $accounts,
        private readonly StudentRecords $students,
        private readonly Guardians $guardians,
        private readonly PlanLimits $limits,
    ) {
        parent::__construct($accounts);
    }

    public function columns(): array
    {
        return [
            'name' => [__('Student name'), true, 'Ayesha Khan'],
            'class' => [__('Class name or code'), true, 'Grade 1'],
            'section' => [__('Section (required if the class has sections)'), false, 'A'],
            'roll_number' => [__('Roll number'), false, '12'],
            'admission_number' => [__('Admission number (blank = next number)'), false, ''],
            'admission_date' => [__('Admission date (YYYY-MM-DD, blank = today)'), false, now()->format('Y-m-d')],
            'gender' => [__('Gender: male, female or other'), false, 'female'],
            'dob' => [__('Date of birth (YYYY-MM-DD)'), false, '2019-03-25'],
            'blood_group' => [__('Blood group, e.g. B+'), false, 'B+'],
            'phone' => [__('Student phone'), false, ''],
            'email' => [__('Student email'), false, ''],
            'username' => [__('Username (blank = made from the name)'), false, ''],
            'address' => [__('Address'), false, '12 Garden Road'],
            'previous_school' => [__('Previous school'), false, ''],
            'guardian_name' => [__('Guardian name'), false, 'Imran Khan'],
            'guardian_relation' => [__('Guardian relation: father, mother, guardian or other'), false, 'father'],
            'guardian_phone' => [__('Guardian phone (links siblings to one account)'), false, '03001234567'],
            'guardian_email' => [__('Guardian email'), false, ''],
            'guardian_occupation' => [__('Guardian occupation'), false, ''],
        ];
    }

    protected function prepare(array $values): array
    {
        $v = fn (string $key) => trim((string) ($values[$key] ?? ''));
        $errors = [];
        $notes = [];

        $name = $v('name');
        if ($name === '') {
            $errors[] = __('Name is missing.');
        } elseif (mb_strlen($name) > 255) {
            $errors[] = __('Name is too long.');
        }

        // Class and section
        $class = $this->findClass($v('class'));
        $section = null;

        if ($v('class') === '') {
            $errors[] = __('Class is missing.');
        } elseif (! $class) {
            $errors[] = __('There is no class called :name.', ['name' => $v('class')]);
        } elseif ($class->sections->isNotEmpty()) {
            $section = $class->sections->first(fn ($section) => mb_strtolower($section->name) === mb_strtolower($v('section')));

            if ($v('section') === '') {
                $errors[] = __(':class has sections: say which one (:sections).', ['class' => $class->name, 'sections' => $class->sections->pluck('name')->implode(', ')]);
            } elseif (! $section) {
                $errors[] = __(':class has no section :section.', ['class' => $class->name, 'section' => $v('section')]);
            }
        } elseif ($v('section') !== '') {
            $errors[] = __(':class has no sections; leave the section blank.', ['class' => $class->name]);
        }

        if (mb_strlen($v('roll_number')) > 20) {
            $errors[] = __('Roll number is too long.');
        }

        // Admission number
        $admissionNumber = $v('admission_number');
        if ($admissionNumber !== '') {
            if (mb_strlen($admissionNumber) > 30) {
                $errors[] = __('Admission number is too long.');
            } elseif (isset($this->seen['admission'][mb_strtolower($admissionNumber)])) {
                $errors[] = __('Admission number :number appears more than once in the file.', ['number' => $admissionNumber]);
            } elseif (StudentProfile::query()->withTrashed()->where('admission_number', $admissionNumber)->exists()) {
                $errors[] = __('Admission number :number is already taken.', ['number' => $admissionNumber]);
            }

            $this->seen['admission'][mb_strtolower($admissionNumber)] = true;
        }

        // Dates and details
        $admissionDate = $this->date($v('admission_date'));
        if ($admissionDate === false) {
            $errors[] = __('Admission date :value isn\'t a date (use YYYY-MM-DD).', ['value' => $v('admission_date')]);
        }

        $dob = $this->date($v('dob'));
        if ($dob === false) {
            $errors[] = __('Date of birth :value isn\'t a date (use YYYY-MM-DD).', ['value' => $v('dob')]);
        } elseif ($dob && $dob->isFuture()) {
            $errors[] = __('Date of birth is in the future.');
        }

        $gender = $this->gender($v('gender'));
        if ($gender === false) {
            $errors[] = __('Gender must be male, female or other.');
        }

        $bloodGroup = strtoupper(str_replace(' ', '', $v('blood_group')));
        if ($bloodGroup !== '' && ! in_array($bloodGroup, self::BLOOD_GROUPS, true)) {
            $errors[] = __('Blood group :value isn\'t recognised.', ['value' => $v('blood_group')]);
        }

        // The student's own sign-in identifiers
        if ($v('email') !== '' && ! filter_var($v('email'), FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Email :value isn\'t valid.', ['value' => $v('email')]);
        }

        if ($v('username') !== '' && ! preg_match('/^[A-Za-z0-9._-]{3,60}$/', $v('username'))) {
            $errors[] = __('Username may only use letters, numbers, dots, dashes and underscores (3 to 60).');
        }

        $this->checkIdentifier('username', __('Username'), $v('username'), $errors);
        $this->checkIdentifier('email', __('Email'), $v('email'), $errors);
        $this->checkIdentifier('phone', __('Phone'), $v('phone'), $errors);

        if ($line = $this->guardianLine($v('phone'), $v('email'))) {
            $errors[] = __('The student\'s phone or email is used by the guardian on line :line.', ['line' => $line]);
        }

        // Guardian
        $guardian = null;
        $hasGuardian = collect(['guardian_name', 'guardian_phone', 'guardian_email', 'guardian_relation', 'guardian_occupation'])->contains(fn ($key) => $v($key) !== '');

        if ($hasGuardian) {
            $relation = GuardianRelation::tryFrom(mb_strtolower($v('guardian_relation')) ?: GuardianRelation::Guardian->value);

            if (! $relation) {
                $errors[] = __('Guardian relation must be father, mother, guardian or other.');
            }

            if ($v('guardian_email') !== '' && ! filter_var($v('guardian_email'), FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('Guardian email :value isn\'t valid.', ['value' => $v('guardian_email')]);
            }

            $existing = $this->guardians->accountMatching($v('guardian_phone'), $v('guardian_email'));
            $earlier = $this->guardianLine($v('guardian_phone'), $v('guardian_email'));

            if ($existing?->trashed()) {
                $errors[] = __('The guardian\'s phone or email belongs to a deleted account.');
            } elseif ($existing?->user_type === UserType::Student || $this->studentLine($v('guardian_phone'), $v('guardian_email'))) {
                $errors[] = __('The guardian\'s phone or email belongs to a student.');
            } elseif ($existing) {
                $notes[] = __('Guardian linked to the existing account of :name.', ['name' => $existing->name]);
            } elseif ($earlier) {
                $notes[] = __('Same guardian as line :line.', ['line' => $earlier]);
            } elseif ($v('guardian_name') === '') {
                $errors[] = __('Guardian name is missing.');
            } elseif ($v('guardian_phone') === '' && $v('guardian_email') === '') {
                $notes[] = __('The guardian has no phone or email, so they get an account of their own.');
            }

            foreach ([$v('guardian_phone'), $v('guardian_email')] as $value) {
                if ($value !== '') {
                    $this->seen['guardian'][mb_strtolower($value)] ??= $this->line;
                }
            }

            $guardian = [
                'name' => $v('guardian_name') ?: (string) $existing?->name,
                'relation' => $relation?->value ?? GuardianRelation::Guardian->value,
                'phone' => $v('guardian_phone') ?: null,
                'email' => $v('guardian_email') ?: null,
                'occupation' => $v('guardian_occupation') ?: null,
                'is_primary' => true,
            ];
        }

        return [
            'errors' => $errors,
            'notes' => $notes,
            'data' => [
                'student' => [
                    'name' => $name,
                    'class_id' => $class?->id,
                    'section_id' => $section?->id,
                    'roll_number' => $v('roll_number') ?: null,
                    'admission_number' => $admissionNumber ?: null,
                    'admission_date' => $admissionDate ? $admissionDate->toDateString() : null,
                    'gender' => $gender ? $gender->value : null,
                    'dob' => $dob ? $dob->toDateString() : null,
                    'blood_group' => $bloodGroup ?: null,
                    'phone' => $v('phone') ?: null,
                    'email' => $v('email') ?: null,
                    'username' => $v('username') ?: null,
                    'address' => $v('address') ?: null,
                    'previous_school' => $v('previous_school') ?: null,
                ],
                'guardian' => $guardian,
            ],
        ];
    }

    public function check(string $path): array
    {
        if (AcademicYear::query()->current()->doesntExist()) {
            throw new ActionFailed(__('Set the current academic year before importing students.'));
        }

        return parent::check($path);
    }

    public function import(array $rows, User $by): ImportResult
    {
        if ($rows === []) {
            throw new ActionFailed(__('There are no valid rows to import.'));
        }

        return DB::transaction(function () use ($rows, $by) {
            $this->limits->ensureCanAdd(tenant(), 'students', count($rows));

            $credentials = [];

            foreach ($rows as $row) {
                $admission = $this->students->admit($row['student'], $row['guardian'] ? [$row['guardian']] : [], $by);

                array_push($credentials, ...$admission->credentials);
            }

            activity('student_profile')->causedBy($by)->withProperties(['imported' => count($rows)])->log('Students imported');

            return new ImportResult(count($rows), $credentials);
        });
    }

    /**
     * Line of an earlier student in the file with this phone or email.
     */
    private function studentLine(string ...$values): ?int
    {
        foreach ($values as $value) {
            if ($value !== '' && isset($this->seen['identity'][mb_strtolower($value)])) {
                return $this->seen['identity'][mb_strtolower($value)];
            }
        }

        return null;
    }

    /**
     * Line of an earlier guardian in the file with this phone or email.
     */
    private function guardianLine(string ...$values): ?int
    {
        foreach ($values as $value) {
            if ($value !== '' && isset($this->seen['guardian'][mb_strtolower($value)])) {
                return $this->seen['guardian'][mb_strtolower($value)];
            }
        }

        return null;
    }

    private function findClass(string $value): ?SchoolClass
    {
        if ($value === '') {
            return null;
        }

        $this->classes ??= SchoolClass::query()->with(['sections' => fn ($query) => $query->ordered()])->get();
        $needle = mb_strtolower($value);

        return $this->classes->first(fn (SchoolClass $class) => mb_strtolower($class->name) === $needle)
            ?? $this->classes->first(fn (SchoolClass $class) => $class->code && mb_strtolower($class->code) === $needle);
    }
}
