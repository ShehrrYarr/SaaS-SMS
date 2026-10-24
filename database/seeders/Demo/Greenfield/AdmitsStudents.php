<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\EnrollmentStatus;
use App\Enums\Gender;
use App\Enums\PaymentMethod;
use App\Enums\StudentStatus;
use App\Models\StudentEnrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Students\StudentRecords;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoData;

/**
 * Families and students: the records the school brought with it (imported
 * on day three), this year's admissions over the summer, a few who joined
 * after term started, and two who have since left.
 */
trait AdmitsStudents
{
    /**
     * Students admitted so far: id => details used by later events.
     *
     * @var array<int, array{student: StudentProfile, family: int, section: string, first: string, gender: Gender, ability: float, absence: float, late: float}>
     */
    private array $pupils = [];

    /** @var list<array<string, mixed>> */
    private array $families = [];

    /** @var array<string, true> full names in use */
    private array $names = [];

    private function admitStudents(): void
    {
        $plans = $this->planPupils();

        usort($plans, fn (array $a, array $b) => [$a['admitted']->getTimestamp(), $a['name']] <=> [$b['admitted']->getTimestamp(), $b['name']]);

        foreach ($plans as $plan) {
            if ($plan['admitted']->lt($this->onboarded)) {
                // On the books already: entered when the school moved its records over.
                $this->as($this->admin, fn () => $this->admit($plan));
            } else {
                $this->at($plan['admitted'], fn () => $this->as($this->admin, fn () => $this->admitNew($plan)));
            }
        }
    }

    /**
     * Who sits where. Seats per section are scaled; the demo sign-in
     * families always exist.
     *
     * @return list<array<string, mixed>>
     */
    private function planPupils(): array
    {
        $free = [];

        foreach (self::CLASSES as $class => [, , , , $sections]) {
            foreach ($sections as $section => $seats) {
                $free[$class.'-'.$section] = $this->scaled($seats, 2);
            }
        }

        $plans = [];
        $take = function (int $family, string $section, string $first, Gender $gender, array $extra = []) use (&$plans, &$free) {
            $plans[] = $this->pupilPlan($family, $section, $first, $gender, $extra);
            $free[$section]--;
        };

        $khan = $this->family([
            'surname' => 'Khan', 'father' => 'Imran Khan', 'father_username' => 'imran.khan', 'father_phone' => '0300-1112233',
            'mother' => 'Saima Imran', 'area' => 'Faisal Town', 'occupation' => 'Civil Engineer',
            'payer' => 'persona', 'method' => PaymentMethod::Cash, 'bus' => true,
        ]);
        $take($khan, 'G3-A', 'Ayesha', Gender::Female, ['username' => 'ayesha.khan', 'ability' => 0.86, 'entry' => 0, 'absence' => 0.015, 'late' => 0.02]);
        $take($khan, 'G1-B', 'Zain', Gender::Male, ['username' => 'zain.khan', 'ability' => 0.74, 'entry' => 0, 'absence' => 0.02, 'late' => 0.03]);

        // A teacher's son: Maryam Iqbal signs in to both the teacher and the parent portal.
        $iqbal = $this->family([
            'surname' => 'Iqbal', 'father' => 'Javed Iqbal', 'mother' => 'Maryam Iqbal', 'mother_phone' => $this->people['maryam']->phone,
            'area' => 'Garden Town', 'occupation' => 'Banker', 'payer' => 'prompt', 'method' => PaymentMethod::BankTransfer, 'bus' => false,
        ]);
        $take($iqbal, 'G2-A', 'Hassan', Gender::Male, ['ability' => 0.8, 'entry' => 0, 'staff_child' => true]);

        // Boarders from out of town, Grades 5 to 8.
        $boarding = array_values(array_filter(array_keys($free), fn (string $key) => in_array(substr($key, 0, 2), ['G5', 'G6', 'G7', 'G8'], true)));

        for ($i = 0, $boarders = $this->scaled(14, 2); $i < $boarders; $i++) {
            $open = array_values(array_filter($boarding, fn (string $key) => $free[$key] > 0));

            if ($open === []) {
                break;
            }

            $gender = $i % 5 < 3 ? Gender::Male : Gender::Female;
            $family = $this->family(['town' => $this->dice->pick(DemoData::HOME_TOWNS), 'bus' => false]);
            $take($family, $this->dice->pick($open), $this->firstName($gender, $family), $gender, ['boarder' => true]);
        }

        // Everyone else, in families of one to three.
        while (array_sum($free) > 0) {
            $byClass = [];

            foreach ($free as $section => $seats) {
                if ($seats > 0) {
                    $byClass[strstr($section, '-', true)][] = $section;
                }
            }

            $children = min((int) $this->dice->weighted([1 => 72, 2 => 22, 3 => 6]), count($byClass));
            $family = $this->family();

            foreach ($this->dice->sample(array_keys($byClass), $children) as $class) {
                $sections = $byClass[$class];
                usort($sections, fn (string $a, string $b) => $free[$b] <=> $free[$a]);
                $gender = $this->dice->chance(50) ? Gender::Male : Gender::Female;

                $take($family, $sections[0], $this->firstName($gender, $family), $gender);
            }
        }

        // Roll numbers in name order within each section (late joiners go last).
        $bySection = [];

        foreach ($plans as $index => $plan) {
            $bySection[$plan['section']][$index] = $plan;
        }

        foreach ($bySection as $rows) {
            uasort($rows, fn (array $a, array $b) => [$a['late'] ? 1 : 0, $a['name']] <=> [$b['late'] ? 1 : 0, $b['name']]);
            $roll = 1;

            foreach (array_keys($rows) as $index) {
                $plans[$index]['roll'] = (string) $roll++;
            }
        }

        return $plans;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function pupilPlan(int $family, string $section, string $first, Gender $gender, array $extra = []): array
    {
        $class = strstr($section, '-', true);
        $level = self::CLASSES[$class][2];
        // The class they joined in: most since Prep, some came from another school.
        $entry = $extra['entry'] ?? ($level === 0 || $this->dice->chance(85) ? 0 : $this->dice->int(1, $level));
        $late = false;

        if ($entry < $level) {
            $admitted = $this->on($this->yearStart->subYears($level - $entry)->addDays($this->dice->int(-25, 5)), '10:00');
        } elseif ($this->dice->chance(12) && ! ($extra['boarder'] ?? false)) {
            $late = true;
            $admitted = $this->on($this->addSchoolDays($this->yearStart, $this->dice->int(4, 22)), sprintf('%02d:%02d', $this->dice->int(9, 12), $this->dice->pick([0, 15, 30, 45])));
        } else {
            $admitted = $this->on($this->schoolDay($this->onboarded->addDays($this->dice->int(6, 40)))->min($this->yearStart->subDays(2)), sprintf('%02d:%02d', $this->dice->int(9, 13), $this->dice->pick([0, 15, 30, 45])));
        }

        $chronic = $this->dice->chance(6);

        return [
            'family' => $family,
            'section' => $section,
            'level' => $level,
            'first' => $first,
            'name' => $first.' '.$this->families[$family]['surname'],
            'username' => $extra['username'] ?? null,
            'gender' => $gender,
            'admitted' => $admitted,
            'late' => $late,
            'transfer' => $entry > 0,
            'boarder' => $extra['boarder'] ?? false,
            'staff_child' => $extra['staff_child'] ?? false,
            'dob' => $this->yearStart->subYears($level + 5)->subDays($this->dice->int(-150, 200)),
            'blood_group' => $this->dice->chance(85) ? (string) $this->dice->weighted(DemoData::BLOOD_GROUPS) : null,
            'medical_notes' => $this->dice->chance(4) ? $this->dice->pick(DemoData::MEDICAL_NOTES) : null,
            'previous_school' => $entry > 0 ? $this->dice->pick(DemoData::PREVIOUS_SCHOOLS) : null,
            'ability' => $extra['ability'] ?? max(0.32, min(0.97, $this->dice->normal(0.7, 0.13))),
            'absence' => $extra['absence'] ?? ($chronic ? 0.1 : 0.022),
            'late_rate' => $extra['late'] ?? ($chronic ? 0.06 : 0.025),
        ];
    }

    /**
     * A new family; $details overrides any part of it.
     *
     * @param  array<string, mixed>  $details
     */
    private function family(array $details = []): int
    {
        $surname = $details['surname'] ?? $this->dice->pick(DemoData::SURNAMES);
        $fatherFirst = $this->dice->pick(DemoData::FATHERS);
        $town = $details['town'] ?? null;
        $area = $details['area'] ?? ($town ? null : $this->dice->pick(array_keys(DemoData::AREAS)));
        $mother = array_key_exists('mother', $details)
            ? $details['mother']
            : ($this->dice->chance(35) ? $this->dice->pick(DemoData::MOTHERS).' '.($this->dice->chance(50) ? $fatherFirst : $surname) : null);
        $index = count($this->families);

        $this->families[] = [
            'surname' => $surname,
            'father' => $details['father'] ?? $fatherFirst.' '.$surname,
            'father_username' => $details['father_username'] ?? null,
            'father_phone' => $details['father_phone'] ?? $this->phone(),
            'father_email' => $this->dice->chance(25) ? strtolower(str_replace(' ', '.', $fatherFirst.' '.$surname)).$index.'@example.com' : null,
            'occupation' => $details['occupation'] ?? $this->dice->pick(DemoData::OCCUPATIONS),
            'mother' => $mother,
            'mother_phone' => $mother ? ($details['mother_phone'] ?? $this->phone()) : null,
            'mother_occupation' => $this->dice->chance(55) ? 'Homemaker' : $this->dice->pick(DemoData::OCCUPATIONS),
            'area' => $area,
            'town' => $town,
            'address' => $town
                ? __('House :house, Street :street, :locality, :town', ['house' => $this->dice->int(1, 300), 'street' => $this->dice->int(1, 20), 'locality' => $this->dice->pick(['Civil Lines', 'Model Town', 'Satellite Town', 'Gulshan Colony', 'Farid Town']), 'town' => $town])
                : DemoData::address($area, $this->dice),
            'payer' => $details['payer'] ?? (string) $this->dice->weighted(['prompt' => 70, 'late' => 14, 'partial' => 8, 'defaulter' => 8]),
            'method' => $details['method'] ?? PaymentMethod::from((string) $this->dice->weighted(['cash' => 50, 'bank_transfer' => 25, 'online' => 18, 'cheque' => 7])),
            'bus' => $details['bus'] ?? ($area !== null && DemoData::AREAS[$area] !== null && $this->dice->chance(38)),
            'parent' => null,
        ];

        return $index;
    }

    private function firstName(Gender $gender, int $family): string
    {
        $surname = $this->families[$family]['surname'];

        for ($try = 0; $try < 12; $try++) {
            $first = $this->dice->pick($gender === Gender::Male ? DemoData::BOYS : DemoData::GIRLS);

            if (! isset($this->names[$first.' '.$surname])) {
                break;
            }
        }

        $this->names[$first.' '.$surname] = true;

        return $first;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function admit(array $plan): StudentProfile
    {
        $family = $this->families[$plan['family']];
        $section = $this->sections[$plan['section']];

        $guardians = [[
            'name' => $family['father'],
            'username' => $family['father_username'],
            'phone' => $family['father_phone'],
            'email' => $family['father_email'],
            'occupation' => $family['occupation'],
            'address' => $family['address'],
            'relation' => 'father',
            'is_primary' => true,
        ]];

        if ($family['mother']) {
            $guardians[] = [
                'name' => $family['mother'],
                'phone' => $family['mother_phone'],
                'occupation' => $family['mother_occupation'],
                'address' => $family['address'],
                'relation' => 'mother',
            ];
        }

        $student = app(StudentRecords::class)->admit([
            'name' => $plan['name'],
            'username' => $plan['username'],
            'class_id' => $section->class_id,
            'section_id' => $section->id,
            'roll_number' => $plan['roll'],
            'gender' => $plan['gender']->value,
            'dob' => $plan['dob']->toDateString(),
            'blood_group' => $plan['blood_group'],
            'address' => $family['address'],
            'admission_date' => $plan['admitted']->toDateString(),
            'previous_school' => $plan['previous_school'],
            'medical_notes' => $plan['medical_notes'],
        ], $guardians, $this->admin)->student;

        if ($plan['admitted']->lt($this->yearStart)) {
            // This year's enrollment starts with the year, not the day they first joined.
            $student->enrollments()->where('academic_year_id', $this->year->id)->update(['enrolled_on' => $this->yearStart->toDateString()]);
        }

        if ($plan['level'] > 0 && $plan['admitted']->lt($this->yearStart->subMonth())) {
            $this->recordLastYear($student, $plan);
        }

        $this->families[$plan['family']]['parent'] ??= $student->parents()->wherePivot('is_primary', true)->first()?->user;

        $this->pupils[$student->id] = [
            'student' => $student,
            'family' => $plan['family'],
            'section' => $plan['section'],
            'first' => $plan['first'],
            'gender' => $plan['gender'],
            'boarder' => $plan['boarder'],
            'staff_child' => $plan['staff_child'],
            'ability' => $plan['ability'],
            'absence' => $plan['absence'],
            'late' => $plan['late_rate'],
        ];

        return $student;
    }

    /**
     * A summer or mid-term admission: the admission fee is invoiced and paid
     * at the office the same day, and a bus seat booked if wanted.
     *
     * @param  array<string, mixed>  $plan
     */
    private function admitNew(array $plan): void
    {
        $student = $this->admit($plan);

        $this->chargeAdmissionFee($student, $plan['family']);

        if ($plan['late'] && $this->families[$plan['family']]['bus']) {
            $this->at($this->on($this->addSchoolDays($plan['admitted'], 1), '11:00'), fn () => $this->bookBus($student));
        }
    }

    /**
     * Last year's class, closed as promoted.
     *
     * @param  array<string, mixed>  $plan
     */
    private function recordLastYear(StudentProfile $student, array $plan): void
    {
        $previous = array_key_first(array_filter(self::CLASSES, fn (array $class) => $class[2] === $plan['level'] - 1));
        $letter = substr($plan['section'], -1);
        $section = $this->sections[$previous.'-'.$letter] ?? $this->sections[$previous.'-A'];
        $lastYearStart = CarbonImmutable::parse($this->lastYear->start_date->toDateString(), self::TIMEZONE);

        StudentEnrollment::query()->create([
            'student_profile_id' => $student->id,
            'academic_year_id' => $this->lastYear->id,
            'class_id' => $this->classes[$previous]->id,
            'section_id' => $section->id,
            'status' => EnrollmentStatus::Promoted,
            'enrolled_on' => $plan['admitted']->max($lastYearStart)->toDateString(),
            'left_on' => $this->lastYear->end_date->toDateString(),
        ]);
    }

    /**
     * Two students who have left this term: one family moved city (the bus
     * seat ends with them), one transferred.
     */
    private function planDepartures(): void
    {
        $leavers = [
            ['G6', StudentStatus::Left, 9, 'Family moved to Islamabad.'],
            ['G4', StudentStatus::Transferred, 23, 'Transferred to a school in Karachi after the father\'s posting.'],
        ];

        foreach ($leavers as [$class, $status, $daysAgo, $remarks]) {
            $day = $this->schoolDay($this->today->subDays($daysAgo), back: true);

            $this->at($this->on($day, '12:30'), function () use ($class, $status, $day, $remarks) {
                $candidates = array_filter($this->pupils, fn (array $pupil) => str_starts_with($pupil['section'], $class.'-')
                    && ! $pupil['boarder'] && ! $pupil['staff_child'] && $pupil['family'] > 1
                    && $pupil['student']->refresh()->status === StudentStatus::Active);

                // Prefer a bus rider, so the seat is seen to end.
                $riders = array_filter($candidates, fn (array $pupil) => $this->families[$pupil['family']]['bus']);
                $pupil = $riders !== [] ? $this->dice->pick($riders) : ($candidates !== [] ? $this->dice->pick($candidates) : null);

                if ($pupil) {
                    $this->as($this->admin, fn () => app(StudentRecords::class)->changeStatus($pupil['student'], $status, $this->date($day), $remarks, $this->admin));
                }
            });
        }
    }

    /**
     * The account of a pupil's primary guardian.
     */
    private function parentOf(int $studentId): ?User
    {
        return $this->families[$this->pupils[$studentId]['family']]['parent'] ?? null;
    }
}
