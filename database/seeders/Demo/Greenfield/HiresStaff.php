<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\UserType;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Leave\LeaveBalances;
use App\Services\Staff\StaffRecords;
use Database\Seeders\Demo\DemoData;
use Illuminate\Support\Str;

/**
 * Teachers, office and support staff, department heads, class teachers and
 * a custom role.
 */
trait HiresStaff
{
    /**
     * key => [name, username, type, roles (null = the type's), designation, department, campus,
     *         years served (null = joins five weeks into the year), qualification, gender, salary]
     */
    private const STAFF = [
        'sana' => ['Sana Malik', 'sana.malik', 'teacher', null, 'Senior Mathematics Teacher', 'maths', 'main', 6, 'M.Sc. Mathematics', 'female', 78000],
        'mehwish' => ['Mehwish Anjum', 'mehwish.anjum', 'teacher', null, 'Head of Junior School', 'junior', 'junior', 9, 'M.A. Education, Montessori Diploma', 'female', 82000],
        'rabia' => ['Rabia Saleem', 'rabia.saleem', 'teacher', null, 'Class Teacher', 'junior', 'junior', 3, 'B.Ed.', 'female', 52000],
        'fariha' => ['Fariha Naz', 'fariha.naz', 'teacher', null, 'Class Teacher', 'junior', 'junior', 4, 'B.Ed.', 'female', 54000],
        'saba' => ['Saba Javed', 'saba.javed', 'teacher', null, 'Class Teacher', 'junior', 'junior', 2, 'B.S. Education', 'female', 50000],
        'hira' => ['Hira Aslam', 'hira.aslam', 'teacher', null, 'Class Teacher', 'junior', 'junior', 5, 'M.A. English', 'female', 56000],
        'amna' => ['Amna Rafiq', 'amna.rafiq', 'teacher', null, 'Class Teacher', 'junior', 'junior', 1, 'B.Ed.', 'female', 48000],
        'shazia' => ['Shazia Parveen', 'shazia.parveen', 'teacher', null, 'Urdu Teacher', 'junior', 'junior', 11, 'M.A. Urdu', 'female', 64000],
        'ghazala' => ['Ghazala Yasmin', 'ghazala.yasmin', 'teacher', null, 'Urdu & Islamiyat Teacher', 'junior', 'junior', 7, 'M.A. Islamic Studies', 'female', 58000],
        'sobia' => ['Sobia Khalid', 'sobia.khalid', 'teacher', null, 'Head of Languages', 'languages', 'main', 8, 'M.Phil. English Literature', 'female', 85000],
        'usman' => ['Usman Ghani', 'usman.ghani', 'teacher', null, 'English Teacher', 'languages', 'main', null, 'M.A. English', 'male', 60000],
        'aslam' => ['Muhammad Aslam', 'muhammad.aslam', 'teacher', null, 'Urdu Teacher', 'languages', 'main', 12, 'M.A. Urdu', 'male', 68000],
        'zubair' => ['Hafiz Zubair Ahmed', 'zubair.ahmed', 'teacher', null, 'Islamiyat Teacher', 'humanities', 'main', 6, 'M.A. Islamic Studies', 'male', 62000],
        'faisal' => ['Faisal Mahmood', 'faisal.mahmood', 'teacher', ['Teacher', 'Exam Coordinator'], 'Mathematics Teacher & Exam Coordinator', 'maths', 'main', 7, 'M.Sc. Mathematics', 'male', 80000],
        'adeel' => ['Adeel Hussain', 'adeel.hussain', 'teacher', null, 'Mathematics Teacher', 'maths', 'main', 3, 'B.S. Mathematics', 'male', 58000],
        'maryam' => ['Maryam Iqbal', 'maryam.iqbal', 'teacher', null, 'Head of Science', 'sciences', 'main', 10, 'M.Sc. Chemistry', 'female', 84000],
        'kiran' => ['Kiran Shahzad', 'kiran.shahzad', 'teacher', null, 'Science Teacher', 'sciences', 'main', 2, 'M.Sc. Physics', 'female', 60000],
        'hamza' => ['Hamza Rauf', 'hamza.rauf', 'teacher', null, 'Computer Teacher', 'sciences', 'main', 4, 'B.S. Computer Science', 'male', 62000],
        'naveed' => ['Naveed Anwar', 'naveed.anwar', 'teacher', null, 'Head of Humanities', 'humanities', 'main', 9, 'M.A. History', 'male', 76000],
        'shahid' => ['Shahid Latif', 'shahid.latif', 'teacher', null, 'Social Studies & Urdu Teacher', 'humanities', 'main', 5, 'M.A. Pakistan Studies', 'male', 58000],
        'hina' => ['Hina Rauf', 'hina.hr', 'hr', null, 'HR Officer', 'admin', 'main', 4, 'MBA (HR)', 'female', 70000],
        'omar' => ['Omar Siddiqui', 'omar.accounts', 'accountant', null, 'Accountant', 'admin', 'main', 6, 'B.Com.', 'male', 72000],
        'laila' => ['Laila Ahmed', 'laila.library', 'librarian', null, 'Librarian', 'support', 'main', 5, 'M.A. Library & Information Science', 'female', 48000],
        'bilal' => ['Bilal Hussain', 'bilal.transport', 'staff', ['Transport Manager'], 'Transport Officer', 'support', 'main', 3, 'B.A.', 'male', 45000],
        'nadia' => ['Nadia Qureshi', 'nadia.hostel', 'staff', ['Hostel Warden'], 'Hostel Warden (Girls)', 'support', 'main', 4, 'B.A.', 'female', 42000],
        'sajid' => ['Sajid Iqbal', 'sajid.iqbal', 'staff', ['Hostel Warden'], 'Hostel Warden (Boys)', 'support', 'main', 6, 'F.A.', 'male', 42000],
    ];

    private const DEPARTMENT_HEADS = [
        'junior' => 'mehwish', 'languages' => 'sobia', 'maths' => 'sana', 'sciences' => 'maryam',
        'humanities' => 'naveed', 'admin' => 'hina', 'support' => 'laila',
    ];

    private const CLASS_TEACHERS = [
        'PREP-A' => 'mehwish', 'PREP-B' => 'rabia', 'G1-A' => 'fariha', 'G1-B' => 'saba', 'G2-A' => 'hira', 'G2-B' => 'amna',
        'G3-A' => 'sana', 'G3-B' => 'maryam', 'G4-A' => 'sobia', 'G4-B' => 'faisal', 'G5-A' => 'kiran', 'G5-B' => 'naveed',
        'G6-A' => 'adeel', 'G7-A' => 'shahid', 'G8-A' => 'zubair',
    ];

    private const BANKS = ['Meezan Bank' => 'MEZN', 'HBL' => 'HABB', 'UBL' => 'UNIL', 'Allied Bank' => 'ABPA', 'Bank Alfalah' => 'ALFH', 'MCB Bank' => 'MUCB'];

    /** @var array<string, StaffProfile> */
    private array $staff = [];

    /** @var array<string, User> staff key => account */
    private array $people = [];

    /** @var array<string, true> phone numbers in use */
    private array $phones = ['0300-4100100' => true, '0300-1112233' => true];

    private function hireStaff(): void
    {
        $this->as($this->admin, function () {
            $this->createExamCoordinatorRole();
            $records = app(StaffRecords::class);

            foreach (self::STAFF as $key => [$name, $username, $type, $roles, $designation, $department, $campus, $years, $qualification, $gender, $salary]) {
                $joined = $years === null
                    ? $this->schoolDay($this->yearStart->addWeeks(5))
                    : $this->yearStart->subYears($years)->addDays($this->dice->int(-20, 5));
                $surname = Str::afterLast($name, ' ');
                $bank = $this->dice->pick(array_keys(self::BANKS));
                $married = $this->dice->chance(65);

                [$profile] = $records->hire([
                    'name' => $name,
                    'username' => $username,
                    'email' => Str::slug($name, '.').'@greenfield.test',
                    'phone' => $this->phone(),
                    'designation' => $designation,
                    'department_id' => $this->departments[$department]->id,
                    'campus_id' => $this->campuses[$campus]->id,
                    'joining_date' => $joined->toDateString(),
                    'qualification' => $qualification,
                    'gender' => $gender,
                    'dob' => $this->yearStart->subYears(($years ?? 0) + $this->dice->int(24, 33))->subDays($this->dice->int(0, 360))->toDateString(),
                    'address' => DemoData::address($this->dice->pick(array_keys(DemoData::AREAS)), $this->dice),
                    'salary' => $salary,
                    'bank_details' => [
                        'bank_name' => $bank,
                        'account_title' => $name,
                        'account_number' => 'PK'.$this->dice->digits(2).self::BANKS[$bank].$this->dice->digits(16),
                        'branch' => $this->dice->pick(['Model Town', 'Johar Town', 'Gulberg', 'Garden Town', 'Faisal Town']).' Branch, Lahore',
                    ],
                    'emergency_contact' => [
                        'name' => match (true) {
                            $married && $gender === 'female' => $this->dice->pick(DemoData::FATHERS).' '.$surname,
                            $married => $this->dice->pick(DemoData::MOTHERS).' '.$surname,
                            default => $this->dice->pick(DemoData::FATHERS).' '.$surname,
                        },
                        'relation' => match (true) {
                            $married && $gender === 'female' => 'Husband',
                            $married => 'Wife',
                            default => $this->dice->pick(['Father', 'Brother']),
                        },
                        'phone' => $this->phone(),
                    ],
                ], UserType::from($type), $roles, $this->admin);

                $this->staff[$key] = $profile;
                $this->people[$key] = $profile->user;
            }

            foreach (self::DEPARTMENT_HEADS as $department => $key) {
                $this->departments[$department]->update(['head_staff_profile_id' => $this->staff[$key]->id]);
            }

            foreach (self::CLASS_TEACHERS as $section => $key) {
                $this->sections[$section]->update(['class_teacher_id' => $this->staff[$key]->id]);
            }

            app(LeaveBalances::class)->ensureAll($this->year);
        });
    }

    /**
     * A role the school made itself: runs exams without the rest of the admin portal.
     */
    private function createExamCoordinatorRole(): void
    {
        $role = Role::create([
            'name' => 'Exam Coordinator',
            'guard_name' => 'web',
            'description' => 'Date sheets, marks and publishing results.',
        ]);

        $wanted = [
            'portal.admin', 'examinations.view', 'examinations.create', 'examinations.edit', 'examinations.publish',
            'marks.view', 'marks.manage', 'grade-scales.view', 'reports.view', 'students.view', 'classes.view', 'subjects.view',
        ];

        $permission = config('permission.models.permission');
        $role->syncPermissions($permission::query()->whereIn('name', $wanted)->where('guard_name', 'web')->pluck('name')->all());

        activity('access')->performedOn($role)->causedBy($this->admin)->log('Role created');
    }

    /**
     * A mobile number not used yet in the school.
     */
    private function phone(): string
    {
        do {
            $phone = '03'.$this->dice->pick(['00', '01', '02', '03', '05', '06', '08', '09', '11', '12', '13', '15', '21', '22', '23', '33', '34', '35', '45', '46']).'-'.$this->dice->digits(7);
        } while (isset($this->phones[$phone]));

        $this->phones[$phone] = true;

        return $phone;
    }
}
