<?php

namespace App\Services\Staff;

use App\Enums\UserType;
use App\Models\Department;
use App\Models\Section;
use App\Models\StaffProfile;
use App\Models\SubjectClass;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Services\Numbering\Sequences;
use App\Services\People\Accounts;
use App\Services\People\Credentials;
use App\Tables\ActionFailed;
use Illuminate\Support\Facades\DB;

/**
 * Staff records: the account (with its roles) and the employment profile.
 */
class StaffRecords
{
    /** Profile columns a form may set. */
    public const PROFILE_FIELDS = [
        'employee_id', 'designation', 'department_id', 'campus_id', 'joining_date', 'qualification',
        'gender', 'dob', 'address', 'salary', 'bank_details', 'emergency_contact',
    ];

    /** Account columns a form may set. */
    public const ACCOUNT_FIELDS = ['name', 'username', 'email', 'phone', 'avatar'];

    public function __construct(
        private readonly PlanLimits $limits,
        private readonly Accounts $accounts,
        private readonly Sequences $sequences,
    ) {}

    /**
     * Add a staff member with a new account.
     *
     * @param  array<string, mixed>  $data  PROFILE_FIELDS + ACCOUNT_FIELDS
     * @param  list<int|string>|null  $roles  role ids or names; null for the type's built-in role
     * @return array{0: StaffProfile, 1: Credentials}
     *
     * @throws ActionFailed
     */
    public function hire(array $data, UserType $type, ?array $roles = null, ?User $by = null): array
    {
        if (! $type->isStaff()) {
            throw new ActionFailed(__('Choose a staff account type.'));
        }

        return DB::transaction(function () use ($data, $type, $roles, $by) {
            $this->limits->ensureCanAdd(tenant(), 'staff');

            $roles ??= array_filter([$type->defaultRole()]);
            $account = $this->accounts->create($type, $this->only($data, self::ACCOUNT_FIELDS), array_values($roles));

            $staff = StaffProfile::query()->create([
                ...$this->only($data, self::PROFILE_FIELDS),
                'user_id' => $account->user->id,
                'employee_id' => filled($data['employee_id'] ?? null) ? $data['employee_id'] : $this->nextEmployeeId(),
                'salary' => filled($data['salary'] ?? null) ? $data['salary'] : 0,
            ]);

            activity('staff_profile')->performedOn($staff)->causedBy($by)
                ->withProperties(['employee_id' => $staff->employee_id, 'type' => $type->value])
                ->log('Staff member added');

            return [$staff, $account->credentials];
        });
    }

    /**
     * @param  array<string, mixed>  $data  PROFILE_FIELDS + ACCOUNT_FIELDS
     */
    public function update(StaffProfile $staff, array $data, ?UserType $type = null): void
    {
        DB::transaction(function () use ($staff, $data, $type) {
            $staff->user->update([
                ...$this->only($data, self::ACCOUNT_FIELDS),
                ...($type && $type->isStaff() ? ['user_type' => $type] : []),
            ]);

            $profile = $this->only($data, self::PROFILE_FIELDS);

            if (array_key_exists('salary', $profile)) {
                $profile['salary'] ??= 0;
            }

            $staff->update($profile);
        });
    }

    /**
     * Remove a staff member and their account. They stop being class
     * teacher, head of department and subject teacher wherever they were.
     */
    public function delete(StaffProfile $staff, ?User $by = null): void
    {
        DB::transaction(function () use ($staff, $by) {
            Section::query()->where('class_teacher_id', $staff->id)->get()->each->update(['class_teacher_id' => null]);
            Department::query()->where('head_staff_profile_id', $staff->id)->get()->each->update(['head_staff_profile_id' => null]);
            SubjectClass::query()->where('teacher_id', $staff->id)->update(['teacher_id' => null]);

            activity('staff_profile')->performedOn($staff)->causedBy($by)
                ->withProperties(['employee_id' => $staff->employee_id, 'name' => $staff->user?->name])
                ->log('Staff member deleted');

            $staff->delete();
            $staff->user?->delete();
        });
    }

    public function nextEmployeeId(): string
    {
        return $this->sequences->next('employee', $this->prefix(), $this->employeeIdTaken(...));
    }

    public function suggestEmployeeId(): string
    {
        return $this->sequences->peek('employee', $this->prefix(), $this->employeeIdTaken(...));
    }

    private function prefix(): string
    {
        return (string) tenant()->setting('numbering.employee_prefix');
    }

    private function employeeIdTaken(string $id): bool
    {
        return StaffProfile::query()->withTrashed()->where('employee_id', $id)->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $data, array $keys): array
    {
        return array_map(fn ($value) => $value === '' ? null : $value, array_intersect_key($data, array_flip($keys)));
    }
}
