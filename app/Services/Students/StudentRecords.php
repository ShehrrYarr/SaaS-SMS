<?php

namespace App\Services\Students;

use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\ParentProfile;
use App\Models\StudentEnrollment;
use App\Models\StudentHostel;
use App\Models\StudentProfile;
use App\Models\StudentTransport;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Services\Hostel\Hostels;
use App\Services\Numbering\Sequences;
use App\Services\People\Accounts;
use App\Services\People\Credentials;
use App\Services\Transport\Transport;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admissions and the life of a student record: details, class placement,
 * status (left, transferred, graduated...) and removal.
 *
 * A student's current class lives on the profile and, for the current
 * academic year, on their enrollment; the two are always changed together.
 */
class StudentRecords
{
    /** Profile columns a form may set. */
    public const PROFILE_FIELDS = [
        'admission_number', 'roll_number', 'class_id', 'section_id', 'gender', 'dob', 'blood_group',
        'address', 'admission_date', 'previous_school', 'medical_notes',
    ];

    /** Account columns a form may set. */
    public const ACCOUNT_FIELDS = ['name', 'username', 'email', 'phone', 'avatar'];

    public function __construct(
        private readonly PlanLimits $limits,
        private readonly Accounts $accounts,
        private readonly Sequences $sequences,
        private readonly Guardians $guardians,
        private readonly Transport $transport,
        private readonly Hostels $hostels,
    ) {}

    /**
     * Admit a student: account, profile, enrollment in the current academic
     * year, and guardians.
     *
     * @param  array<string, mixed>  $data  PROFILE_FIELDS + ACCOUNT_FIELDS
     * @param  list<array{parent_id?: int|null, name?: string, phone?: string|null, email?: string|null, occupation?: string|null, relation: string, is_primary?: bool}>  $guardians
     *
     * @throws ActionFailed
     */
    public function admit(array $data, array $guardians = [], ?User $by = null): Admission
    {
        return DB::transaction(function () use ($data, $guardians, $by) {
            $school = tenant();

            $this->limits->ensureCanAdd($school, 'students');
            $year = $this->currentYear();

            $account = $this->accounts->create(UserType::Student, $this->only($data, self::ACCOUNT_FIELDS), UserType::Student->defaultRole());

            $student = StudentProfile::query()->create([
                ...$this->only($data, self::PROFILE_FIELDS),
                'user_id' => $account->user->id,
                'admission_number' => filled($data['admission_number'] ?? null) ? $data['admission_number'] : $this->nextAdmissionNumber(),
                'admission_date' => $data['admission_date'] ?? $school->today()->toDateString(),
                'status' => StudentStatus::Active,
            ]);

            $student->enrollments()->create([
                'academic_year_id' => $year->id,
                'class_id' => $student->class_id,
                'section_id' => $student->section_id,
                'roll_number' => $student->roll_number,
                'status' => EnrollmentStatus::Active,
                'enrolled_on' => $student->admission_date,
            ]);

            $credentials = [$account->credentials];

            foreach ($guardians as $guardian) {
                $credentials[] = $this->addGuardian($student, $guardian);
            }

            activity('student_profile')->performedOn($student)->causedBy($by)
                ->withProperties(['admission_number' => $student->admission_number, 'class_id' => $student->class_id, 'section_id' => $student->section_id])
                ->log('Student admitted');

            return new Admission($student, array_values(array_filter($credentials)));
        });
    }

    /**
     * Save a student's details. A class or section change also moves their
     * current enrollment.
     *
     * @param  array<string, mixed>  $data  PROFILE_FIELDS + ACCOUNT_FIELDS (username, email, phone...)
     */
    public function update(StudentProfile $student, array $data): void
    {
        DB::transaction(function () use ($student, $data) {
            $student->user->update($this->only($data, self::ACCOUNT_FIELDS));

            $profile = $this->only($data, self::PROFILE_FIELDS);
            $placement = array_intersect_key($profile, array_flip(['class_id', 'section_id', 'roll_number']));

            $student->update(array_diff_key($profile, $placement));

            if ($placement !== []) {
                $this->move($student, (int) ($placement['class_id'] ?? $student->class_id), $placement['section_id'] ?? null, $placement['roll_number'] ?? null);
            }
        });
    }

    /**
     * Put a student in another class or section within the current year.
     */
    public function move(StudentProfile $student, int $classId, int|string|null $sectionId, ?string $rollNumber = null): void
    {
        DB::transaction(function () use ($student, $classId, $sectionId, $rollNumber) {
            $student->update([
                'class_id' => $classId,
                'section_id' => $sectionId ?: null,
                'roll_number' => $rollNumber ?: null,
            ]);

            if (! $student->wasChanged(['class_id', 'section_id', 'roll_number'])) {
                return;
            }

            $year = AcademicYear::query()->current()->first();

            if ($year && $student->status === StudentStatus::Active) {
                StudentEnrollment::query()->updateOrCreate(
                    ['student_profile_id' => $student->id, 'academic_year_id' => $year->id],
                    [
                        'class_id' => $student->class_id,
                        'section_id' => $student->section_id,
                        'roll_number' => $student->roll_number,
                        'status' => EnrollmentStatus::Active,
                        'enrolled_on' => $student->admission_date ?? tenant()->today(),
                    ],
                );
            }
        });
    }

    /**
     * Mark a student as left, transferred, graduated or suspended, or take
     * them back. Students who aren't active can't sign in, and don't count
     * towards the plan's student limit.
     *
     * @throws ActionFailed
     */
    public function changeStatus(StudentProfile $student, StudentStatus $status, ?Carbon $on = null, ?string $remarks = null, ?User $by = null): void
    {
        if ($student->status === $status) {
            return;
        }

        DB::transaction(function () use ($student, $status, $on, $remarks, $by) {
            if ($status === StudentStatus::Active && $student->status !== StudentStatus::Suspended) {
                $this->limits->ensureCanAdd(tenant(), 'students');
            }

            $from = $student->status;
            $student->update(['status' => $status]);

            $student->user->update(['status' => match ($status) {
                StudentStatus::Active => UserStatus::Active,
                StudentStatus::Suspended => UserStatus::Suspended,
                default => UserStatus::Inactive,
            }]);

            $enrollment = $this->currentEnrollment($student);

            if ($enrollment) {
                $enrollment->update(match ($status) {
                    StudentStatus::Active, StudentStatus::Suspended => ['status' => EnrollmentStatus::Active, 'left_on' => null],
                    StudentStatus::Graduated => ['status' => EnrollmentStatus::Graduated, 'left_on' => $on ?? tenant()->today()],
                    StudentStatus::Transferred => ['status' => EnrollmentStatus::Transferred, 'left_on' => $on ?? tenant()->today()],
                    StudentStatus::Left => ['status' => EnrollmentStatus::Left, 'left_on' => $on ?? tenant()->today()],
                } + ($remarks ? ['remarks' => $remarks] : []));
            }

            if (in_array($status, [StudentStatus::Graduated, StudentStatus::Transferred, StudentStatus::Left], true)) {
                $this->endServices($student, $on ?? tenant()->today(), $by);
            }

            activity('student_profile')->performedOn($student)->causedBy($by)
                ->withProperties(array_filter(['from' => $from->value, 'to' => $status->value, 'on' => $on?->toDateString(), 'remarks' => $remarks]))
                ->log('Student status changed');
        });
    }

    /**
     * Remove a student and their account. Their records (attendance, marks,
     * invoices) stay, linked to the deleted profile.
     */
    public function delete(StudentProfile $student, ?User $by = null): void
    {
        DB::transaction(function () use ($student, $by) {
            activity('student_profile')->performedOn($student)->causedBy($by)
                ->withProperties(['admission_number' => $student->admission_number, 'name' => $student->user?->name])
                ->log('Student deleted');

            $this->endServices($student, tenant()->today(), $by);
            $student->delete();
            $student->user?->delete();
        });
    }

    /**
     * A student who leaves stops riding school transport and checks out of
     * the hostel (on the day they left, or the day those started if later).
     */
    private function endServices(StudentProfile $student, Carbon $on, ?User $by): void
    {
        foreach (StudentTransport::query()->active()->where('student_profile_id', $student->id)->get() as $assignment) {
            $this->transport->end($assignment, $on->toDateString() < $assignment->start_date->toDateString() ? $assignment->start_date : $on, $by, notify: false);
        }

        foreach (StudentHostel::query()->active()->where('student_profile_id', $student->id)->get() as $stay) {
            $this->hostels->checkOut($stay, $on->toDateString() < $stay->check_in->toDateString() ? $stay->check_in : $on, $by, notify: false);
        }
    }

    /**
     * Link a guardian given as an existing parent id or as new details.
     *
     * @param  array{parent_id?: int|null, name?: string, phone?: string|null, email?: string|null, occupation?: string|null, relation: string, is_primary?: bool}  $guardian
     * @return Credentials|null for a newly created parent account
     *
     * @throws ActionFailed
     */
    public function addGuardian(StudentProfile $student, array $guardian): ?Credentials
    {
        $credentials = null;

        if (filled($guardian['parent_id'] ?? null)) {
            $parent = ParentProfile::query()->findOrFail($guardian['parent_id']);
        } else {
            [$parent, $credentials] = $this->guardians->findOrCreate($guardian);
        }

        $this->guardians->link($student, $parent, $guardian['relation'], (bool) ($guardian['is_primary'] ?? false));

        return $credentials;
    }

    public function nextAdmissionNumber(): string
    {
        return $this->sequences->next('admission', $this->admissionPrefix(), $this->admissionNumberTaken(...));
    }

    public function suggestAdmissionNumber(): string
    {
        return $this->sequences->peek('admission', $this->admissionPrefix(), $this->admissionNumberTaken(...));
    }

    /**
     * The student's enrollment in the current academic year.
     */
    public function currentEnrollment(StudentProfile $student): ?StudentEnrollment
    {
        $year = AcademicYear::query()->current()->value('id');

        return $year ? $student->enrollments()->where('academic_year_id', $year)->first() : null;
    }

    /**
     * @throws ActionFailed
     */
    private function currentYear(): AcademicYear
    {
        return AcademicYear::query()->current()->first()
            ?? throw new ActionFailed(__('Set the current academic year before admitting students.'));
    }

    private function admissionPrefix(): string
    {
        return (string) tenant()->setting('numbering.admission_prefix');
    }

    private function admissionNumberTaken(string $number): bool
    {
        return StudentProfile::query()->withTrashed()->where('admission_number', $number)->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $data, array $keys): array
    {
        $values = array_intersect_key($data, array_flip($keys));

        return array_map(fn ($value) => $value === '' ? null : $value, $values);
    }
}
