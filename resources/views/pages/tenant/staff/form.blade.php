<?php

/*
 * Add or edit a staff member: account (type, roles, status), employment,
 * personal details, emergency contact and bank details. Shared by the
 * School Admin and HR portals.
 */

use App\Enums\Gender;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campus;
use App\Models\Department;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Access\AccessGuard;
use App\Services\Billing\PlanLimits;
use App\Services\Staff\StaffRecords;
use App\Support\Portal;
use App\Tables\ActionFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\WithFileUploads;

use function Livewire\Volt\{computed, layout, mount, protect, rules, state, title, updated, uses};

layout('layouts.app');
title(fn () => $this->staff ? __('Edit staff member') : __('Add staff member'));

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['portal' => 'admin', 'staff' => null, 'userId' => null, 'done' => false, 'credentials' => null, 'savedId' => null])->locked();

state([
    // Account
    'name' => '', 'username' => '', 'email' => '', 'phone' => '', 'user_type' => UserType::Teacher->value, 'roles' => [], 'status' => UserStatus::Active->value,
    'photo' => null, 'remove_photo' => false,
    // Employment
    'employee_id' => '', 'designation' => '', 'department_id' => '', 'campus_id' => '', 'joining_date' => '', 'qualification' => '', 'salary' => '',
    // Personal
    'gender' => '', 'dob' => '', 'address' => '',
    'emergency_name' => '', 'emergency_phone' => '', 'emergency_relation' => '',
    // Bank
    'bank_name' => '', 'bank_account_title' => '', 'bank_account_number' => '', 'bank_branch' => '',
]);

$types = protect(fn () => collect(UserType::cases())
    ->filter(fn (UserType $type) => $type->isStaff() && $type !== UserType::SchoolAdmin)
    ->mapWithKeys(fn (UserType $type) => [$type->value => $type->label()])
    ->all());

mount(function (?int $staff = null) {
    $this->portal = Portal::current() === 'hr' ? 'hr' : 'admin';

    if (! $staff) {
        $this->authorize('staff.create');
        $this->joining_date = tenant()->today()->toDateString();
        $this->campus_id = (string) (Campus::main()?->id ?? '');
        $this->roles = $this->defaultRoleIds(UserType::Teacher);

        return;
    }

    $this->authorize('staff.edit');

    $record = StaffProfile::query()->with('user.roles')->findOrFail($staff);
    abort_unless($record->user && app(AccessGuard::class)->canManage(auth()->user(), $record->user), 403);

    $bank = $record->bank_details ?? [];
    $emergency = $record->emergency_contact ?? [];

    $this->staff = $record->id;
    $this->userId = $record->user_id;
    $this->fill([
        'name' => $record->user->name,
        'username' => (string) $record->user->username,
        'email' => (string) $record->user->email,
        'phone' => (string) $record->user->phone,
        'user_type' => $record->user->user_type->value,
        'roles' => $record->user->roles->pluck('id')->map(fn ($id) => (string) $id)->all(),
        'status' => $record->user->status->value,
        'employee_id' => $record->employee_id,
        'designation' => (string) $record->designation,
        'department_id' => (string) ($record->department_id ?? ''),
        'campus_id' => (string) ($record->campus_id ?? ''),
        'joining_date' => (string) $record->joining_date?->toDateString(),
        'qualification' => (string) $record->qualification,
        'salary' => $record->salary !== null ? (string) (float) $record->salary : '',
        'gender' => (string) $record->gender?->value,
        'dob' => (string) $record->dob?->toDateString(),
        'address' => (string) $record->address,
        'emergency_name' => (string) ($emergency['name'] ?? ''),
        'emergency_phone' => (string) ($emergency['phone'] ?? ''),
        'emergency_relation' => (string) ($emergency['relation'] ?? ''),
        'bank_name' => (string) ($bank['bank_name'] ?? ''),
        'bank_account_title' => (string) ($bank['account_title'] ?? ''),
        'bank_account_number' => (string) ($bank['account_number'] ?? ''),
        'bank_branch' => (string) ($bank['branch'] ?? ''),
    ]);
});

$allRoles = computed(fn () => Role::query()->forCurrentSchool()->with('permissions')->orderBy('name')->get()
    // Student and Parent roles belong to student and parent records.
    ->reject(fn (Role $role) => in_array($role->name, [UserType::Student->defaultRole(), UserType::Parent->defaultRole()], true))
    ->values());

$assignable = computed(fn () => $this->allRoles
    ->filter(fn (Role $role) => app(AccessGuard::class)->canAssign(auth()->user(), $role))
    ->pluck('id')
    ->all());

$defaultRoleIds = protect(fn (UserType $type) => $this->allRoles
    ->where('name', $type->defaultRole())
    ->pluck('id')
    ->intersect($this->assignable)
    ->map(fn ($id) => (string) $id)
    ->values()
    ->all());

$departments = computed(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all());

$campuses = computed(fn () => Campus::query()->ordered()->pluck('name', 'id')->all());

$suggestedId = computed(fn () => app(StaffRecords::class)->suggestEmployeeId());

$remaining = computed(fn () => app(PlanLimits::class)->remaining(tenant(), 'staff'));

$photoUrl = computed(fn () => $this->userId && ! $this->remove_photo ? User::query()->find($this->userId)?->avatarUrl() : null);

// A new staff member gets the built-in role of their type.
updated(['user_type' => function () {
    if (! $this->staff && ($type = UserType::tryFrom($this->user_type))) {
        $this->roles = $this->defaultRoleIds($type);
    }
}]);

rules(function () {
    $school = tenant()->id;

    return [
        'name' => ['required', 'string', 'max:255'],
        'username' => [$this->staff ? 'required' : 'nullable', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->where('school_id', $school)->ignore($this->userId)],
        'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('school_id', $school)->ignore($this->userId)],
        'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->where('school_id', $school)->ignore($this->userId)],
        'user_type' => ['required', Rule::in(array_keys($this->types()))],
        'roles' => ['array'],
        'roles.*' => ['integer'],
        'status' => ['required', Rule::enum(UserStatus::class)],
        'photo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        'remove_photo' => ['boolean'],

        'employee_id' => ['nullable', 'string', 'max:30', Rule::unique('staff_profiles', 'employee_id')->where('school_id', $school)->ignore($this->staff)],
        'designation' => ['nullable', 'string', 'max:100'],
        'department_id' => ['nullable', Rule::exists('departments', 'id')->where('school_id', $school)->whereNull('deleted_at')],
        'campus_id' => ['nullable', Rule::exists('campuses', 'id')->where('school_id', $school)->whereNull('deleted_at')],
        'joining_date' => ['nullable', 'date'],
        'qualification' => ['nullable', 'string', 'max:255'],
        'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],

        'gender' => ['nullable', Rule::enum(Gender::class)],
        'dob' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
        'address' => ['nullable', 'string', 'max:500'],
        'emergency_name' => ['nullable', 'string', 'max:255'],
        'emergency_phone' => ['nullable', 'string', 'max:30'],
        'emergency_relation' => ['nullable', 'string', 'max:50'],

        'bank_name' => ['nullable', 'string', 'max:100'],
        'bank_account_title' => ['nullable', 'string', 'max:150'],
        'bank_account_number' => ['nullable', 'string', 'max:50'],
        'bank_branch' => ['nullable', 'string', 'max:150'],
    ];
})->attributes([
    'user_type' => __('staff type'),
    'department_id' => __('department'),
    'campus_id' => __('campus'),
    'dob' => __('date of birth'),
])->messages(['username.regex' => __('Use letters, numbers, dots, dashes or underscores.')]);

$storePhoto = protect(function (?string $current): ?string {
    if ($this->photo) {
        if ($current) {
            Storage::disk('public')->delete($current);
        }

        return $this->photo->storeAs('schools/'.tenant()->id.'/avatars', Str::lower(Str::random(24)).'.'.strtolower($this->photo->getClientOriginalExtension()), 'public');
    }

    if ($this->remove_photo && $current) {
        Storage::disk('public')->delete($current);

        return null;
    }

    return $current;
});

$save = function (StaffRecords $records) {
    $this->authorize($this->staff ? 'staff.edit' : 'staff.create');
    $data = $this->validate();

    $filled = fn (array $values) => array_filter($values, fn ($value) => filled($value)) ?: null;

    $fields = [
        ...collect($data)->only(['name', 'username', 'email', 'phone', 'employee_id', 'designation', 'department_id', 'campus_id', 'joining_date', 'qualification', 'salary', 'gender', 'dob', 'address'])->all(),
        'emergency_contact' => $filled(['name' => $data['emergency_name'], 'phone' => $data['emergency_phone'], 'relation' => $data['emergency_relation']]),
        'bank_details' => $filled(['bank_name' => $data['bank_name'], 'account_title' => $data['bank_account_title'], 'account_number' => $data['bank_account_number'], 'branch' => $data['bank_branch']]),
    ];

    $type = UserType::from($data['user_type']);
    $wanted = array_values(array_intersect(array_map('intval', $data['roles']), $this->allRoles->pluck('id')->all(), $this->assignable));

    try {
        DB::transaction(function () use ($records, $fields, $type, $wanted, $data) {
            if (! $this->staff) {
                $fields['avatar'] = $this->storePhoto(null);
                [$staff, $credentials] = $records->hire($fields, $type, $wanted, auth()->user());

                $this->savedId = $staff->id;
                $this->credentials = $credentials->toArray();

                return;
            }

            $staff = StaffProfile::query()->with('user.roles')->findOrFail($this->staff);
            abort_unless(app(AccessGuard::class)->canManage(auth()->user(), $staff->user), 403);

            $fields['avatar'] = $this->storePhoto($staff->user->avatar);
            $records->update($staff, $fields, $type);

            // Roles the editor can't hold stay as they are.
            $untouchable = array_diff($staff->user->roles->pluck('id')->all(), $this->assignable);
            $staff->user->syncRoles(array_values(array_unique([...$wanted, ...$untouchable])));
            $staff->user->update(['status' => $data['status']]);

            $this->savedId = $staff->id;
        });
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    if ($this->credentials) {
        $this->done = true;
        $this->js('window.scrollTo({ top: 0, behavior: "smooth" })');

        return;
    }

    $this->flashToast(__('Staff details saved.'));
    $this->redirect(Portal::route($this->portal, 'staff.show', $this->savedId), navigate: true);
};

$addAnother = function () {
    $this->authorize('staff.create');
    $this->redirect(Portal::route($this->portal, 'staff.create'), navigate: true);
};

?>

@php
    $editing = (bool) $staff;
    $title = $editing ? __('Edit staff member') : __('Add staff member');
    $breadcrumbs = [Portal::label($portal) => route(Portal::dashboardRoute($portal)), __('Staff') => Portal::route($portal, 'staff.index')]
        + ($editing ? [$name => Portal::route($portal, 'staff.show', $staff)] : [])
        + [$title => null];
    $blocked = ! $editing && $this->remaining === 0;
    $symbol = tenant()->setting('currency_symbol');
@endphp

<div>
    <x-page-header :title="$title" :breadcrumbs="$breadcrumbs" />

    @if ($done)
        <div class="row justify-content-center">
            <div class="col-xl-7">
                <div class="card">
                    <div class="card-body p-5">
                        <div class="d-flex align-items-center gap-3 mb-4">
                            <span class="avatar-item avatar-lg avatar-title bg-success-subtle text-success rounded-circle fs-3"><i class="ri-checkbox-circle-line"></i></span>
                            <div>
                                <h5 class="mb-1">{{ __(':name has been added', ['name' => $name]) }}</h5>
                                <p class="text-muted mb-0">{{ __('Hand over these sign-in details. The password is shown only on this screen.') }}</p>
                            </div>
                        </div>
                        <x-credentials :url="tenant()->loginUrl()" :username="$credentials['username']" :password="$credentials['password']" />
                        <div class="d-flex flex-wrap gap-2 mt-5">
                            <a href="{{ Portal::route($portal, 'staff.show', $savedId) }}" wire:navigate class="btn btn-primary"><i class="ri-user-line me-1"></i>{{ __('View profile') }}</a>
                            <button type="button" class="btn btn-light" wire:click="addAnother"><i class="ri-user-add-line me-1"></i>{{ __('Add another') }}</button>
                            <a href="{{ Portal::route($portal, 'staff.index') }}" wire:navigate class="btn btn-link">{{ __('Back to staff') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        @if ($blocked)
            <div class="alert alert-danger d-flex gap-2 align-items-start">
                <i class="ri-forbid-line fs-5 lh-1"></i>
                <span>{{ __('The school has reached its plan\'s staff limit. Ask the platform administrator to upgrade the plan.') }}</span>
            </div>
        @endif

        <form wire:submit="save" novalidate>
            <fieldset @disabled($blocked)>
                <div class="row g-4">
                    <div class="col-xl-8">
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">{{ __('Employment') }}</h5></div>
                            <div class="card-body">
                                <x-form.input name="name" :label="__('Full name')" required maxlength="255" autocomplete="off" />
                                <div class="row">
                                    <div class="col-sm-6">
                                        <x-form.input name="employee_id" :label="__('Employee ID')" maxlength="30" :placeholder="$editing ? null : $this->suggestedId"
                                                      :hint="$editing ? null : __('Leave blank to use the next number (:number).', ['number' => $this->suggestedId])" />
                                    </div>
                                    <div class="col-sm-6"><x-form.input name="designation" :label="__('Designation')" maxlength="100" :placeholder="__('e.g. Senior Teacher')" /></div>
                                    <div class="col-sm-6"><x-form.select name="department_id" :label="__('Department')" :options="$this->departments" :placeholder="__('None')" /></div>
                                    @if (count($this->campuses) > 1)
                                        <div class="col-sm-6"><x-form.select name="campus_id" :label="__('Campus')" :options="$this->campuses" :placeholder="__('None')" /></div>
                                    @endif
                                    <div class="col-sm-6"><x-form.input name="joining_date" type="date" :label="__('Joining date')" /></div>
                                    <div class="col-sm-6"><x-form.input name="salary" type="number" step="0.01" min="0" :label="__('Monthly salary (:symbol)', ['symbol' => $symbol])" /></div>
                                </div>
                                <x-form.input name="qualification" :label="__('Qualification')" maxlength="255" wrapper="mb-0" />
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">{{ __('Personal') }}</h5></div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-sm-6"><x-form.select name="gender" :label="__('Gender')" :options="Gender::options()" /></div>
                                    <div class="col-sm-6"><x-form.input name="dob" type="date" :label="__('Date of birth')" max="{{ now()->toDateString() }}" /></div>
                                </div>
                                <x-form.textarea name="address" :label="__('Address')" rows="2" maxlength="500" />
                                <h6 class="mb-3">{{ __('Emergency contact') }}</h6>
                                <div class="row">
                                    <div class="col-sm-5"><x-form.input name="emergency_name" :label="__('Name')" maxlength="255" wrapper="mb-4 mb-sm-0" /></div>
                                    <div class="col-sm-4"><x-form.input name="emergency_phone" type="tel" :label="__('Phone')" maxlength="30" wrapper="mb-4 mb-sm-0" /></div>
                                    <div class="col-sm-3"><x-form.input name="emergency_relation" :label="__('Relation')" maxlength="50" wrapper="mb-0" /></div>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-4 mb-xl-0">
                            <div class="card-header">
                                <h5 class="card-title mb-0">{{ __('Bank details') }}</h5>
                                <small class="text-muted"><i class="ri-lock-line me-1"></i>{{ __('Stored encrypted. Used for salary payments.') }}</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-sm-6"><x-form.input name="bank_name" :label="__('Bank')" maxlength="100" /></div>
                                    <div class="col-sm-6"><x-form.input name="bank_branch" :label="__('Branch')" maxlength="150" /></div>
                                    <div class="col-sm-6"><x-form.input name="bank_account_title" :label="__('Account title')" maxlength="150" wrapper="mb-4 mb-sm-0" /></div>
                                    <div class="col-sm-6"><x-form.input name="bank_account_number" :label="__('Account number / IBAN')" maxlength="50" autocomplete="off" wrapper="mb-0" /></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">{{ __('Photo') }}</h5></div>
                            <div class="card-body d-flex align-items-center gap-4">
                                <span class="entity-avatar entity-avatar-lg rounded-circle">
                                    @if ($photo && ! $errors->has('photo'))
                                        <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('New photo preview') }}">
                                    @elseif ($this->photoUrl)
                                        <img src="{{ $this->photoUrl }}" alt="{{ __('Current photo') }}">
                                    @else
                                        <i class="ri-user-3-line"></i>
                                    @endif
                                </span>
                                <div>
                                    <label class="btn btn-sm btn-light mb-0" for="field-photo"><i class="ri-upload-2-line me-1"></i>{{ __('Upload') }}</label>
                                    <input type="file" id="field-photo" class="d-none" wire:model="photo" accept="image/png,image/jpeg,image/webp">
                                    @if ($photo || $this->photoUrl)
                                        <button type="button" class="btn btn-sm btn-link text-danger" wire:click="$set('remove_photo', true); $set('photo', null)">{{ __('Remove') }}</button>
                                    @endif
                                    <div class="form-text mt-1">{{ __('PNG, JPG or WebP, up to 2 MB.') }}</div>
                                    @error('photo') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">{{ __('Account & access') }}</h5></div>
                            <div class="card-body">
                                <x-form.select name="user_type" :label="__('Staff type')" :options="$this->types()" :placeholder="false" required live />
                                <x-form.input name="username" :label="__('Username')" :required="$editing" maxlength="60" autocomplete="off" spellcheck="false"
                                              :hint="$editing ? null : __('Leave blank to make one from the name.')" />
                                <x-form.input name="email" type="email" :label="__('Email')" maxlength="255" />
                                <x-form.input name="phone" type="tel" :label="__('Phone')" maxlength="30" />

                                <fieldset class="mb-4">
                                    <legend class="form-label fs-14">{{ __('Roles') }}</legend>
                                    <div class="vstack gap-2">
                                        @foreach ($this->allRoles as $role)
                                            @php($allowed = in_array($role->id, $this->assignable, true))
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="role-{{ $role->id }}" value="{{ $role->id }}" wire:model="roles" @disabled(! $allowed)>
                                                <label class="form-check-label" for="role-{{ $role->id }}">
                                                    {{ $role->name }}
                                                    @unless ($allowed)<small class="d-block text-warning">{{ __('Has permissions you don\'t hold.') }}</small>@endunless
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="form-text">{{ __('Roles decide which portals and screens they can use.') }}</div>
                                </fieldset>

                                @if ($editing)
                                    <x-form.select name="status" :label="__('Account status')" :options="UserStatus::options()" :placeholder="false" required wrapper="mb-0"
                                                   :hint="__('Inactive and suspended accounts can\'t sign in.')" />
                                @else
                                    <p class="text-muted fs-13 mb-0"><i class="ri-information-line me-1"></i>{{ __('A temporary password is generated and shown after saving.') }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="card mb-0">
                            <div class="card-body d-grid gap-2">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,photo">
                                    <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ $editing ? __('Save changes') : __('Add staff member') }}</span>
                                    <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
                                </button>
                                <a href="{{ $editing ? Portal::route($portal, 'staff.show', $staff) : Portal::route($portal, 'staff.index') }}" wire:navigate class="btn btn-light">{{ __('Cancel') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>
        </form>
    @endif
</div>
