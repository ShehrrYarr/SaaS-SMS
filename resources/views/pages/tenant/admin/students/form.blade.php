<?php

/*
 * Admit a student, or edit one: details, class placement, sign-in account
 * and guardians. A new admission shows the sign-in details of the student
 * and of any new parent accounts once.
 */

use App\Enums\Gender;
use App\Enums\GuardianRelation;
use App\Enums\StudentStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\AcademicYear;
use App\Models\ParentProfile;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Services\Billing\PlanLimits;
use App\Services\Imports\StudentImport;
use App\Services\Students\Guardians;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\Uploads;
use Livewire\WithFileUploads;

use function Livewire\Volt\{computed, layout, mount, protect, rules, state, title, updated, uses};

layout('layouts.app');
title(fn () => $this->student ? __('Edit student') : __('Admit student'));

uses([InteractsWithUi::class, WithFileUploads::class]);

state(['student' => null, 'userId' => null, 'done' => false, 'credentials' => [], 'admittedId' => null])->locked();

state([
    // Student
    'name' => '', 'gender' => '', 'dob' => '', 'blood_group' => '', 'address' => '', 'previous_school' => '', 'medical_notes' => '',
    'photo' => null, 'remove_photo' => false,
    // Admission
    'admission_number' => '', 'admission_date' => '', 'class_id' => '', 'section_id' => '', 'roll_number' => '',
    // Sign-in
    'username' => '', 'email' => '', 'phone' => '',
    // Guardians: [['mode' => 'existing'|'new', 'parent_id', 'label', 'name', 'phone', 'email', 'occupation', 'relation', 'is_primary']]
    'guardians' => [],
    'parentSearch' => '', 'searchRow' => null,
]);

$blankGuardian = protect(fn (string $mode = 'new', bool $primary = false) => [
    'mode' => $mode, 'parent_id' => '', 'label' => '', 'name' => '', 'phone' => '', 'email' => '', 'occupation' => '',
    'relation' => GuardianRelation::Father->value, 'is_primary' => $primary,
]);

mount(function (?int $student = null) {
    if (! $student) {
        $this->authorize('students.create');
        $this->admission_date = tenant()->today()->toDateString();
        $this->guardians = [$this->blankGuardian('new', true)];

        return;
    }

    $this->authorize('students.edit');

    $record = StudentProfile::query()->with(['user', 'parents.user'])->findOrFail($student);

    $this->student = $record->id;
    $this->userId = $record->user_id;
    $this->fill([
        'name' => $record->user->name,
        'username' => (string) $record->user->username,
        'email' => (string) $record->user->email,
        'phone' => (string) $record->user->phone,
        'gender' => (string) $record->gender?->value,
        'dob' => (string) $record->dob?->toDateString(),
        'blood_group' => (string) $record->blood_group,
        'address' => (string) $record->address,
        'previous_school' => (string) $record->previous_school,
        'medical_notes' => (string) $record->medical_notes,
        'admission_number' => $record->admission_number,
        'admission_date' => (string) $record->admission_date?->toDateString(),
        'class_id' => (string) ($record->class_id ?? ''),
        'section_id' => (string) ($record->section_id ?? ''),
        'roll_number' => (string) $record->roll_number,
        'guardians' => $record->parents->map(fn (ParentProfile $parent) => [
            ...$this->blankGuardian('existing'),
            'parent_id' => (string) $parent->id,
            'label' => $parent->user?->name.($parent->user?->phone ? ' · '.$parent->user->phone : ''),
            'relation' => $parent->pivot->relation?->value ?? GuardianRelation::Guardian->value,
            'is_primary' => (bool) $parent->pivot->is_primary,
        ])->values()->all(),
    ]);
});

$classes = computed(fn () => SchoolClass::query()->ordered()->with(['sections' => fn ($query) => $query->ordered()])->get());

$sections = computed(fn () => $this->classes->firstWhere('id', (int) $this->class_id)?->sections ?? collect());

$currentYear = computed(fn () => AcademicYear::query()->current()->first());

$suggestedNumber = computed(fn () => app(StudentRecords::class)->suggestAdmissionNumber());

$remaining = computed(fn () => app(PlanLimits::class)->remaining(tenant(), 'students'));

$photoUrl = computed(fn () => $this->userId && ! $this->remove_photo
    ? App\Models\User::query()->find($this->userId)?->avatarUrl()
    : null);

// Parent accounts matching the search box of the guardian row being edited.
$parentResults = computed(function () {
    $term = trim($this->parentSearch);

    if (mb_strlen($term) < 2) {
        return collect();
    }

    $like = '%'.addcslashes($term, '%_\\').'%';
    $taken = collect($this->guardians)->pluck('parent_id')->filter()->all();

    return ParentProfile::query()
        ->with(['user', 'children.user'])
        ->whereNotIn('id', $taken)
        ->whereHas('user', fn ($query) => $query->where(fn ($query) => $query
            ->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)->orWhere('username', 'like', $like)))
        ->limit(8)
        ->get();
});

// Existing accounts that new guardians' phone or email already belong to.
$guardianMatches = computed(function () {
    $guardians = app(Guardians::class);

    return collect($this->guardians)
        ->map(fn (array $row) => $row['mode'] === 'new' && (filled($row['phone']) || filled($row['email']))
            ? $guardians->accountMatching($row['phone'], $row['email'])
            : null)
        ->all();
});

updated(['class_id' => function () {
    $this->section_id = '';
    unset($this->sections);
}]);

$addGuardian = function (string $mode = 'new') {
    if (count($this->guardians) >= 4) {
        return;
    }

    $this->guardians[] = $this->blankGuardian($mode, $this->guardians === []);
};

$removeGuardian = function (int $index) {
    $wasPrimary = (bool) ($this->guardians[$index]['is_primary'] ?? false);

    unset($this->guardians[$index]);
    $this->guardians = array_values($this->guardians);

    if ($wasPrimary && $this->guardians !== []) {
        $this->guardians[0]['is_primary'] = true;
    }

    $this->resetValidation();
};

$makePrimary = function (int $index) {
    foreach ($this->guardians as $i => $row) {
        $this->guardians[$i]['is_primary'] = $i === $index;
    }
};

$startSearch = function (int $index) {
    $this->searchRow = $index;
    $this->parentSearch = '';
};

$pickParent = function (int $index, int $parentId) {
    $parent = ParentProfile::query()->with('user')->findOrFail($parentId);

    if (! isset($this->guardians[$index])) {
        return;
    }

    $this->guardians[$index]['mode'] = 'existing';
    $this->guardians[$index]['parent_id'] = (string) $parent->id;
    $this->guardians[$index]['label'] = $parent->user?->name.($parent->user?->phone ? ' · '.$parent->user->phone : '');
    $this->searchRow = null;
    $this->parentSearch = '';
};

rules(function () {
    $school = tenant()->id;
    $sectionRequired = $this->class_id && $this->sections->isNotEmpty();

    return [
        'name' => ['required', 'string', 'max:255'],
        'gender' => ['nullable', Rule::enum(Gender::class)],
        'dob' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
        'blood_group' => ['nullable', Rule::in(StudentImport::BLOOD_GROUPS)],
        'address' => ['nullable', 'string', 'max:500'],
        'previous_school' => ['nullable', 'string', 'max:255'],
        'medical_notes' => ['nullable', 'string', 'max:1000'],
        'photo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        'remove_photo' => ['boolean'],

        'admission_number' => ['nullable', 'string', 'max:30', Rule::unique('student_profiles', 'admission_number')->where('school_id', $school)->ignore($this->student)],
        'admission_date' => ['nullable', 'date'],
        'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $school)->whereNull('deleted_at')],
        'section_id' => [$sectionRequired ? 'required' : 'nullable', Rule::exists('sections', 'id')->where('class_id', (int) $this->class_id)->whereNull('deleted_at')],
        'roll_number' => ['nullable', 'string', 'max:20', function ($attribute, $value, $fail) {
            $taken = StudentProfile::query()
                ->where('class_id', (int) $this->class_id)
                ->where('section_id', $this->section_id ?: null)
                ->where('roll_number', $value)
                ->where('status', StudentStatus::Active)
                ->when($this->student, fn ($query) => $query->whereKeyNot($this->student))
                ->exists();

            if ($taken) {
                $fail(__('Another student in this class already has roll number :roll.', ['roll' => $value]));
            }
        }],

        'username' => [$this->student ? 'required' : 'nullable', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->where('school_id', $school)->ignore($this->userId)],
        'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('school_id', $school)->ignore($this->userId)],
        'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->where('school_id', $school)->ignore($this->userId)],

        'guardians' => ['array', 'max:4'],
        'guardians.*.mode' => ['required', Rule::in(['existing', 'new'])],
        'guardians.*.parent_id' => ['nullable', 'required_if:guardians.*.mode,existing', 'distinct', Rule::exists('parent_profiles', 'id')->where('school_id', $school)->whereNull('deleted_at')],
        'guardians.*.name' => ['nullable', 'required_if:guardians.*.mode,new', 'string', 'max:255'],
        'guardians.*.phone' => ['nullable', 'string', 'max:30'],
        'guardians.*.email' => ['nullable', 'email', 'max:255'],
        'guardians.*.occupation' => ['nullable', 'string', 'max:100'],
        'guardians.*.relation' => ['required', Rule::enum(GuardianRelation::class)],
        'guardians.*.is_primary' => ['boolean'],
    ];
})->attributes([
    'class_id' => __('class'),
    'section_id' => __('section'),
    'dob' => __('date of birth'),
    'guardians.*.parent_id' => __('parent'),
    'guardians.*.name' => __('guardian name'),
    'guardians.*.phone' => __('guardian phone'),
    'guardians.*.email' => __('guardian email'),
    'guardians.*.relation' => __('relation'),
])->messages([
    'username.regex' => __('Use letters, numbers, dots, dashes or underscores.'),
    'guardians.*.parent_id.required_if' => __('Search for the parent and pick them from the list.'),
    'guardians.*.parent_id.distinct' => __('This parent is listed twice.'),
    'guardians.*.name.required_if' => __('Enter the guardian\'s name.'),
]);

$storePhoto = protect(function (?string $current): ?string {
    if ($this->photo) {
        if ($current) {
            Storage::disk('public')->delete($current);
        }

        return Uploads::store($this->photo, 'schools/'.tenant()->id.'/avatars', Str::lower(Str::random(24)), Uploads::IMAGES, 'public');
    }

    if ($this->remove_photo && $current) {
        Storage::disk('public')->delete($current);

        return null;
    }

    return $current;
});

$save = function (StudentRecords $records, Guardians $guardianLinks) {
    $this->authorize($this->student ? 'students.edit' : 'students.create');
    $data = $this->validate();

    $fields = collect($data)->only([...StudentRecords::PROFILE_FIELDS, 'name', 'username', 'email', 'phone'])->all();

    try {
        if (! $this->student) {
            $admission = DB::transaction(function () use ($records, $fields, $data) {
                $fields['avatar'] = $this->storePhoto(null);

                return $records->admit($fields, $this->guardianRows($data['guardians']), auth()->user());
            });

            $this->done = true;
            $this->admittedId = $admission->student->id;
            $this->credentials = array_map(fn ($item) => $item->toArray(), $admission->credentials);
            $this->reset('photo');
            $this->js('window.scrollTo({ top: 0, behavior: "smooth" })');

            return;
        }

        DB::transaction(function () use ($records, $guardianLinks, $fields, $data) {
            $student = StudentProfile::query()->with(['user', 'parents'])->findOrFail($this->student);
            $fields['avatar'] = $this->storePhoto($student->user->avatar);

            $records->update($student, $fields);

            // Guardians: unlink removed ones, then link/update the rest.
            $keep = collect($data['guardians'])->where('mode', 'existing')->pluck('parent_id')->map(fn ($id) => (int) $id)->all();

            foreach ($student->parents as $parent) {
                if (! in_array($parent->id, $keep, true)) {
                    $guardianLinks->unlink($student, $parent);
                }
            }

            $new = [];
            foreach ($this->guardianRows($data['guardians']) as $row) {
                $created = $records->addGuardian($student, $row);

                if ($created) {
                    $new[] = $created->toArray();
                }
            }

            $this->credentials = $new;
        });
    } catch (ActionFailed $e) {
        $this->toast($e->getMessage(), 'error');

        return;
    }

    if ($this->credentials) {
        // New guardian accounts: show their sign-in details before leaving.
        $this->done = true;
        $this->admittedId = $this->student;
        $this->js('window.scrollTo({ top: 0, behavior: "smooth" })');

        return;
    }

    $this->flashToast(__('Student details saved.'));
    $this->redirectRoute('admin.students.show', ['student' => $this->student], navigate: true);
};

/**
 * Guardian rows for StudentRecords: existing parents by id, new ones by details.
 */
$guardianRows = protect(fn (array $rows) => collect($rows)->map(fn (array $row) => $row['mode'] === 'existing'
    ? ['parent_id' => (int) $row['parent_id'], 'relation' => $row['relation'], 'is_primary' => (bool) $row['is_primary']]
    : [
        'name' => trim((string) $row['name']),
        'phone' => filled($row['phone']) ? trim($row['phone']) : null,
        'email' => filled($row['email']) ? trim($row['email']) : null,
        'occupation' => filled($row['occupation']) ? trim($row['occupation']) : null,
        'relation' => $row['relation'],
        'is_primary' => (bool) $row['is_primary'],
    ])->values()->all());

$admitAnother = function () {
    $this->authorize('students.create');
    $this->redirectRoute('admin.students.create', navigate: true);
};

?>

@php
    $editing = (bool) $student;
    $title = $editing ? __('Edit student') : __('Admit student');
    $breadcrumbs = [__('School Admin') => route('admin.dashboard'), __('Students') => route('admin.students.index')]
        + ($editing ? [$name => route('admin.students.show', $student)] : [])
        + [$title => null];
    $blocked = ! $editing && (! $this->currentYear || $this->remaining === 0);
@endphp

<div>
    <x-page-header :title="$title" :breadcrumbs="$breadcrumbs" />

    @if ($done)
        <div class="row justify-content-center">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-body p-5">
                        <div class="d-flex align-items-center gap-3 mb-4">
                            <span class="avatar-item avatar-lg avatar-title bg-success-subtle text-success rounded-circle fs-3"><i class="ri-checkbox-circle-line"></i></span>
                            <div>
                                <h5 class="mb-1">{{ $editing ? __('Saved') : __(':name has been admitted', ['name' => $name]) }}</h5>
                                <p class="text-muted mb-0">{{ __('Hand these sign-in details to each person. Passwords are shown only on this screen.') }}</p>
                            </div>
                        </div>

                        @foreach ($credentials as $item)
                            <h6 class="mt-4 mb-2">{{ $item['name'] }} <span class="badge bg-light text-muted border ms-1">{{ $item['role'] }}</span></h6>
                            <x-credentials :url="tenant()->loginUrl()" :username="$item['username']" :password="$item['password']" />
                        @endforeach

                        <div class="d-flex flex-wrap gap-2 mt-5">
                            <a href="{{ route('admin.students.show', $admittedId) }}" wire:navigate class="btn btn-primary"><i class="ri-user-line me-1"></i>{{ __('View profile') }}</a>
                            @if (! $editing)
                                <button type="button" class="btn btn-light" wire:click="admitAnother"><i class="ri-user-add-line me-1"></i>{{ __('Admit another student') }}</button>
                            @endif
                            <a href="{{ route('admin.students.index') }}" wire:navigate class="btn btn-link">{{ __('Back to students') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        @if (! $editing && ! $this->currentYear)
            <div class="alert alert-warning d-flex gap-2 align-items-start">
                <i class="ri-error-warning-line fs-5 lh-1"></i>
                <span>{{ __('Students are enrolled in the current academic year, and none is set.') }}
                    @can('academic-years.view')<a href="{{ route('admin.academic-years.index') }}" wire:navigate class="alert-link">{{ __('Set the current year') }}</a>@endcan
                </span>
            </div>
        @elseif (! $editing && $this->remaining === 0)
            <div class="alert alert-danger d-flex gap-2 align-items-start">
                <i class="ri-forbid-line fs-5 lh-1"></i>
                <span>{{ __('The school has reached its plan\'s student limit. Ask the platform administrator to upgrade the plan.') }}</span>
            </div>
        @endif

        <form wire:submit="save" novalidate>
            <fieldset @disabled($blocked)>
                <div class="row g-4">
                    <div class="col-xl-8">
                        {{-- Student --}}
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">{{ __('Student') }}</h5></div>
                            <div class="card-body">
                                <x-form.input name="name" :label="__('Full name')" required maxlength="255" autocomplete="off" />
                                <div class="row">
                                    <div class="col-sm-4"><x-form.select name="gender" :label="__('Gender')" :options="Gender::options()" /></div>
                                    <div class="col-sm-4"><x-form.input name="dob" type="date" :label="__('Date of birth')" max="{{ now()->toDateString() }}" /></div>
                                    <div class="col-sm-4"><x-form.select name="blood_group" :label="__('Blood group')" :options="array_combine(StudentImport::BLOOD_GROUPS, StudentImport::BLOOD_GROUPS)" /></div>
                                </div>
                                <x-form.textarea name="address" :label="__('Address')" rows="2" maxlength="500" />
                                <div class="row">
                                    <div class="col-sm-6"><x-form.input name="previous_school" :label="__('Previous school')" maxlength="255" wrapper="mb-4 mb-sm-0" /></div>
                                    <div class="col-sm-6"><x-form.textarea name="medical_notes" :label="__('Medical notes')" rows="1" maxlength="1000" wrapper="mb-0"
                                                                           :hint="__('Allergies or conditions staff should know about.')" /></div>
                                </div>
                            </div>
                        </div>

                        {{-- Admission --}}
                        <div class="card mb-4">
                            <div class="card-header hstack gap-2">
                                <h5 class="card-title mb-0 flex-grow-1">{{ __('Admission & class') }}</h5>
                                @if ($this->currentYear)
                                    <span class="badge bg-primary-subtle text-primary">{{ $this->currentYear->name }}</span>
                                @endif
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-sm-6">
                                        <x-form.input name="admission_number" :label="__('Admission number')" maxlength="30"
                                                      :placeholder="$editing ? null : $this->suggestedNumber"
                                                      :hint="$editing ? null : __('Leave blank to use the next number (:number).', ['number' => $this->suggestedNumber])" />
                                    </div>
                                    <div class="col-sm-6"><x-form.input name="admission_date" type="date" :label="__('Admission date')" /></div>
                                    <div class="col-sm-5">
                                        <x-form.select name="class_id" :label="__('Class')" required live
                                                       :options="$this->classes->mapWithKeys(fn ($class) => [$class->id => $class->name])->all()" wrapper="mb-4 mb-sm-0" />
                                    </div>
                                    <div class="col-sm-4">
                                        @if ($this->sections->isNotEmpty())
                                            <x-form.select name="section_id" :label="__('Section')" required wrapper="mb-4 mb-sm-0"
                                                           :options="$this->sections->mapWithKeys(fn ($section) => [$section->id => $section->name])->all()" />
                                        @else
                                            <label class="form-label">{{ __('Section') }}</label>
                                            <p class="form-control-plaintext text-muted mb-4 mb-sm-0">{{ $class_id ? __('No sections') : __('Choose a class first') }}</p>
                                        @endif
                                    </div>
                                    <div class="col-sm-3"><x-form.input name="roll_number" :label="__('Roll no.')" maxlength="20" wrapper="mb-0" /></div>
                                </div>
                                @if ($this->classes->isEmpty())
                                    <div class="alert alert-warning mt-4 mb-0">
                                        {{ __('There are no classes yet.') }}
                                        @can('classes.view')<a href="{{ route('admin.classes.index') }}" wire:navigate class="alert-link">{{ __('Create classes') }}</a>@endcan
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Guardians --}}
                        <div class="card mb-4 mb-xl-0">
                            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">{{ __('Parents & guardians') }}</h5>
                                    <small class="text-muted">{{ __('Each guardian gets a parent portal account. Siblings share one parent account.') }}</small>
                                </div>
                                @if (count($guardians) < 4)
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-subtle-primary" wire:click="addGuardian('new')"><i class="ri-user-add-line me-1"></i>{{ __('New parent') }}</button>
                                        <button type="button" class="btn btn-subtle-secondary" wire:click="addGuardian('existing')"><i class="ri-links-line me-1"></i>{{ __('Existing parent') }}</button>
                                    </div>
                                @endif
                            </div>
                            <div class="card-body vstack gap-3">
                                @forelse ($guardians as $i => $guardian)
                                    @php($match = $this->guardianMatches[$i] ?? null)
                                    <div class="border rounded-2 p-3" wire:key="guardian-{{ $i }}-{{ $guardian['mode'] }}-{{ $guardian['parent_id'] }}">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                            <span class="fw-medium flex-grow-1">
                                                {{ $guardian['mode'] === 'existing' ? __('Existing parent') : __('New parent') }}
                                            </span>
                                            <div class="form-check form-check-inline mb-0">
                                                <input type="radio" class="form-check-input" id="primary-{{ $i }}" name="primary-guardian" @checked($guardian['is_primary']) wire:click="makePrimary({{ $i }})">
                                                <label class="form-check-label fs-13" for="primary-{{ $i }}">{{ __('Primary contact') }}</label>
                                            </div>
                                            <button type="button" class="btn btn-subtle-danger icon-btn-sm" wire:click="removeGuardian({{ $i }})" aria-label="{{ __('Remove guardian') }}">
                                                <i class="ri-close-line"></i>
                                            </button>
                                        </div>

                                        <div class="row">
                                            @if ($guardian['mode'] === 'existing')
                                                <div class="col-sm-8 position-relative">
                                                    <label class="form-label" for="guardian-search-{{ $i }}">{{ __('Parent') }}<span class="text-danger ms-1">*</span></label>
                                                    @if ($guardian['parent_id'] && $searchRow !== $i)
                                                        <div class="input-group">
                                                            <span class="form-control bg-light">{{ $guardian['label'] }}</span>
                                                            <button type="button" class="btn btn-light" wire:click="startSearch({{ $i }})">{{ __('Change') }}</button>
                                                        </div>
                                                    @else
                                                        <input type="search" id="guardian-search-{{ $i }}" autocomplete="off"
                                                               @class(['form-control', 'is-invalid' => $errors->has("guardians.{$i}.parent_id")])
                                                               placeholder="{{ __('Search by name, phone or email…') }}"
                                                               wire:focus="$set('searchRow', {{ $i }})" wire:model.live.debounce.300ms="parentSearch">
                                                        @if ($searchRow === $i && mb_strlen(trim($parentSearch)) >= 2)
                                                            <div class="list-group position-absolute shadow-sm z-3" style="left: calc(var(--bs-gutter-x) * .5); right: calc(var(--bs-gutter-x) * .5)">
                                                                @forelse ($this->parentResults as $result)
                                                                    <button type="button" class="list-group-item list-group-item-action" wire:click="pickParent({{ $i }}, {{ $result->id }})">
                                                                        <span class="fw-medium">{{ $result->user?->name }}</span>
                                                                        <small class="text-muted ms-1">{{ $result->user?->phone ?: $result->user?->email }}</small>
                                                                        @if ($result->children->isNotEmpty())
                                                                            <small class="d-block text-muted">{{ __('Parent of :names', ['names' => $result->children->map(fn ($child) => $child->user?->name)->filter()->implode(', ')]) }}</small>
                                                                        @endif
                                                                    </button>
                                                                @empty
                                                                    <div class="list-group-item text-muted fs-13">{{ __('No parent matches. Add them as a new parent instead.') }}</div>
                                                                @endforelse
                                                            </div>
                                                        @endif
                                                        @error("guardians.{$i}.parent_id") <div class="invalid-feedback">{{ $message }}</div> @enderror
                                                    @endif
                                                </div>
                                                <div class="col-sm-4">
                                                    <x-form.select :name="'guardians.'.$i.'.relation'" :label="__('Relation')" :options="GuardianRelation::options()" :placeholder="false" required wrapper="mb-0" />
                                                </div>
                                            @else
                                                <div class="col-sm-8"><x-form.input :name="'guardians.'.$i.'.name'" :label="__('Name')" required maxlength="255" /></div>
                                                <div class="col-sm-4"><x-form.select :name="'guardians.'.$i.'.relation'" :label="__('Relation')" :options="GuardianRelation::options()" :placeholder="false" required /></div>
                                                <div class="col-sm-4"><x-form.input :name="'guardians.'.$i.'.phone'" type="tel" :label="__('Phone')" maxlength="30" live wrapper="mb-4 mb-sm-0" /></div>
                                                <div class="col-sm-4"><x-form.input :name="'guardians.'.$i.'.email'" type="email" :label="__('Email')" maxlength="255" live wrapper="mb-4 mb-sm-0" /></div>
                                                <div class="col-sm-4"><x-form.input :name="'guardians.'.$i.'.occupation'" :label="__('Occupation')" maxlength="100" wrapper="mb-0" /></div>
                                                @if ($match)
                                                    <div class="col-12">
                                                        <div @class(['alert py-2 px-3 mt-3 mb-0 fs-13', 'alert-danger' => $match->user_type === App\Enums\UserType::Student || $match->trashed(), 'alert-info' => ! ($match->user_type === App\Enums\UserType::Student || $match->trashed())])>
                                                            @if ($match->trashed())
                                                                {{ __('This phone or email belongs to a deleted account, so it can\'t be used.') }}
                                                            @elseif ($match->user_type === App\Enums\UserType::Student)
                                                                {{ __('This phone or email belongs to a student, so it can\'t be used for a parent.') }}
                                                            @else
                                                                {{ __('Belongs to :name\'s existing account: the student is linked to it and no new account is created.', ['name' => $match->name]) }}
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-muted mb-0">{{ __('No guardians added. You can add them later from the student\'s profile.') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4">
                        {{-- Photo --}}
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
                                    <div wire:loading wire:target="photo" class="form-text"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Uploading…') }}</div>
                                    @error('photo') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>

                        {{-- Sign-in --}}
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">{{ __('Student sign-in') }}</h5></div>
                            <div class="card-body">
                                <x-form.input name="username" :label="__('Username')" :required="$editing" maxlength="60" autocomplete="off" spellcheck="false"
                                              :hint="$editing ? null : __('Leave blank to make one from the name.')" />
                                <x-form.input name="email" type="email" :label="__('Email')" maxlength="255" />
                                <x-form.input name="phone" type="tel" :label="__('Phone')" maxlength="30" wrapper="mb-0" />
                                @unless ($editing)
                                    <p class="text-muted fs-13 mt-3 mb-0"><i class="ri-information-line me-1"></i>{{ __('A temporary password is generated and shown after saving.') }}</p>
                                @endunless
                            </div>
                        </div>

                        <div class="card mb-0">
                            <div class="card-body d-grid gap-2">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,photo">
                                    <span wire:loading.remove wire:target="save"><i class="ri-save-line me-1"></i>{{ $editing ? __('Save changes') : __('Admit student') }}</span>
                                    <span wire:loading wire:target="save"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
                                </button>
                                <a href="{{ $editing ? route('admin.students.show', $student) : route('admin.students.index') }}" wire:navigate class="btn btn-light">{{ __('Cancel') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>
        </form>
    @endif
</div>
