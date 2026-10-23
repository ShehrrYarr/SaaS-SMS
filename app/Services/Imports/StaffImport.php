<?php

namespace App\Services\Imports;

use App\Enums\UserType;
use App\Models\Department;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Services\People\Accounts;
use App\Services\Staff\StaffRecords;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff from CSV. Each person gets an account with the built-in role of
 * their type (teacher, HR, accountant, librarian; "staff" gets no role).
 */
class StaffImport extends RowImport
{
    /** Types the import accepts, by the words people write. */
    public const TYPES = [
        'teacher' => UserType::Teacher,
        'hr' => UserType::Hr,
        'accountant' => UserType::Accountant,
        'librarian' => UserType::Librarian,
        'staff' => UserType::Staff,
    ];

    /** @var Collection<int, Department>|null */
    private ?Collection $departments = null;

    public function __construct(
        Accounts $accounts,
        private readonly StaffRecords $staff,
        private readonly PlanLimits $limits,
    ) {
        parent::__construct($accounts);
    }

    public function columns(): array
    {
        return [
            'name' => [__('Full name'), true, 'Sana Malik'],
            'type' => [__('Type: teacher, hr, accountant, librarian or staff'), true, 'teacher'],
            'employee_id' => [__('Employee ID (blank = next number)'), false, ''],
            'designation' => [__('Designation'), false, 'Senior Teacher'],
            'department' => [__('Department name'), false, ''],
            'joining_date' => [__('Joining date (YYYY-MM-DD)'), false, now()->format('Y-m-d')],
            'phone' => [__('Phone'), false, '03007654321'],
            'email' => [__('Email'), false, ''],
            'username' => [__('Username (blank = made from the name)'), false, ''],
            'gender' => [__('Gender: male, female or other'), false, 'female'],
            'dob' => [__('Date of birth (YYYY-MM-DD)'), false, ''],
            'qualification' => [__('Qualification'), false, 'M.Sc. Mathematics'],
            'salary' => [__('Monthly salary'), false, '45000'],
        ];
    }

    protected function prepare(array $values): array
    {
        $v = fn (string $key) => trim((string) ($values[$key] ?? ''));
        $errors = [];

        $name = $v('name');
        if ($name === '') {
            $errors[] = __('Name is missing.');
        } elseif (mb_strlen($name) > 255) {
            $errors[] = __('Name is too long.');
        }

        $type = self::TYPES[mb_strtolower($v('type'))] ?? null;
        if (! $type) {
            $errors[] = $v('type') === ''
                ? __('Type is missing.')
                : __('Type :value isn\'t one of: :types.', ['value' => $v('type'), 'types' => implode(', ', array_keys(self::TYPES))]);
        }

        $employeeId = $v('employee_id');
        if ($employeeId !== '') {
            if (mb_strlen($employeeId) > 30) {
                $errors[] = __('Employee ID is too long.');
            } elseif (isset($this->seen['employee'][mb_strtolower($employeeId)])) {
                $errors[] = __('Employee ID :id appears more than once in the file.', ['id' => $employeeId]);
            } elseif (StaffProfile::query()->withTrashed()->where('employee_id', $employeeId)->exists()) {
                $errors[] = __('Employee ID :id is already taken.', ['id' => $employeeId]);
            }

            $this->seen['employee'][mb_strtolower($employeeId)] = $this->line;
        }

        $department = null;
        if ($v('department') !== '') {
            $this->departments ??= Department::query()->get();
            $department = $this->departments->first(fn (Department $department) => mb_strtolower($department->name) === mb_strtolower($v('department')));

            if (! $department) {
                $errors[] = __('There is no department called :name.', ['name' => $v('department')]);
            }
        }

        $joining = $this->date($v('joining_date'));
        if ($joining === false) {
            $errors[] = __('Joining date :value isn\'t a date (use YYYY-MM-DD).', ['value' => $v('joining_date')]);
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

        $salary = str_replace([',', ' '], '', $v('salary'));
        if ($salary !== '' && (! is_numeric($salary) || (float) $salary < 0 || (float) $salary > 9999999999)) {
            $errors[] = __('Salary :value isn\'t a valid amount.', ['value' => $v('salary')]);
        }

        if ($v('email') !== '' && ! filter_var($v('email'), FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Email :value isn\'t valid.', ['value' => $v('email')]);
        }

        if ($v('username') !== '' && ! preg_match('/^[A-Za-z0-9._-]{3,60}$/', $v('username'))) {
            $errors[] = __('Username may only use letters, numbers, dots, dashes and underscores (3 to 60).');
        }

        $this->checkIdentifier('username', __('Username'), $v('username'), $errors);
        $this->checkIdentifier('email', __('Email'), $v('email'), $errors);
        $this->checkIdentifier('phone', __('Phone'), $v('phone'), $errors);

        foreach (['designation' => 100, 'qualification' => 255] as $key => $max) {
            if (mb_strlen($v($key)) > $max) {
                $errors[] = __(':field is too long.', ['field' => ucfirst($key)]);
            }
        }

        return [
            'errors' => $errors,
            'data' => [
                'type' => $type?->value,
                'staff' => [
                    'name' => $name,
                    'employee_id' => $employeeId ?: null,
                    'designation' => $v('designation') ?: null,
                    'department_id' => $department?->id,
                    'joining_date' => $joining ? $joining->toDateString() : null,
                    'phone' => $v('phone') ?: null,
                    'email' => $v('email') ?: null,
                    'username' => $v('username') ?: null,
                    'gender' => $gender ? $gender->value : null,
                    'dob' => $dob ? $dob->toDateString() : null,
                    'qualification' => $v('qualification') ?: null,
                    'salary' => $salary === '' ? 0 : round((float) $salary, 2),
                ],
            ],
        ];
    }

    public function import(array $rows, User $by): ImportResult
    {
        if ($rows === []) {
            throw new ActionFailed(__('There are no valid rows to import.'));
        }

        return DB::transaction(function () use ($rows, $by) {
            $this->limits->ensureCanAdd(tenant(), 'staff', count($rows));

            $credentials = [];

            foreach ($rows as $row) {
                [, $credentials[]] = $this->staff->hire($row['staff'], UserType::from($row['type']), null, $by);
            }

            activity('staff_profile')->causedBy($by)->withProperties(['imported' => count($rows)])->log('Staff imported');

            return new ImportResult(count($rows), $credentials);
        });
    }
}
