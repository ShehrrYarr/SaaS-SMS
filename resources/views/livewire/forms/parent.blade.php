<?php

/*
 * Add or edit a parent and the children they are linked to.
 *     $dispatch('open-modal', { component: 'forms.parent', size: 'lg' })
 *     $dispatch('open-modal', { component: 'forms.parent', params: { parent: 3 }, size: 'lg' })
 */

use App\Enums\GuardianRelation;
use App\Enums\UserType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Services\Students\Guardians;
use App\Services\Students\ParentRecords;
use App\Tables\ActionFailed;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['parent' => null, 'userId' => null, 'done' => false, 'credentials' => null])->locked();

state([
    'name' => '', 'username' => '', 'phone' => '', 'email' => '', 'occupation' => '', 'address' => '',
    // [['id' => student id, 'label' => 'Ali Khan · Grade 3 A', 'relation' => 'father', 'is_primary' => bool]]
    'children' => [],
    'studentSearch' => '',
]);

mount(function (?int $parent = null) {
    if (! $parent) {
        $this->authorize('parents.create');

        return;
    }

    $this->authorize('parents.edit');

    $record = ParentProfile::query()->with(['user', 'children.user', 'children.schoolClass', 'children.section'])->findOrFail($parent);

    $this->parent = $record->id;
    $this->userId = $record->user_id;
    $this->fill([
        'name' => $record->user->name,
        'username' => (string) $record->user->username,
        'phone' => (string) $record->user->phone,
        'email' => (string) $record->user->email,
        'occupation' => (string) $record->occupation,
        'address' => (string) $record->address,
        'children' => $record->children->map(fn (StudentProfile $child) => [
            'id' => $child->id,
            'label' => $child->user?->name.($child->placementLabel() ? ' · '.$child->placementLabel() : ''),
            'relation' => $child->pivot->relation?->value ?? GuardianRelation::Guardian->value,
            'is_primary' => (bool) $child->pivot->is_primary,
        ])->values()->all(),
    ]);
});

$studentResults = computed(function () {
    $term = trim($this->studentSearch);

    if (mb_strlen($term) < 2) {
        return collect();
    }

    $like = '%'.addcslashes($term, '%_\\').'%';

    return StudentProfile::query()
        ->with(['user', 'schoolClass', 'section'])
        ->whereNotIn('id', collect($this->children)->pluck('id')->all())
        ->where(fn ($query) => $query
            ->where('admission_number', 'like', $like)
            ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like)))
        ->limit(8)
        ->get();
});

// The existing account a new parent's phone or email belongs to.
$match = computed(fn () => ! $this->parent && (filled($this->phone) || filled($this->email))
    ? app(Guardians::class)->accountMatching($this->phone, $this->email)
    : null);

$addChild = function (int $studentId) {
    $student = StudentProfile::query()->with(['user', 'schoolClass', 'section'])->findOrFail($studentId);

    if (collect($this->children)->contains('id', $student->id) || count($this->children) >= 10) {
        return;
    }

    $this->children[] = [
        'id' => $student->id,
        'label' => $student->user?->name.($student->placementLabel() ? ' · '.$student->placementLabel() : ''),
        'relation' => GuardianRelation::Father->value,
        'is_primary' => false,
    ];
    $this->studentSearch = '';
};

$removeChild = function (int $index) {
    unset($this->children[$index]);
    $this->children = array_values($this->children);
};

rules(fn () => [
    'name' => ['required', 'string', 'max:255'],
    'username' => [$this->parent ? 'required' : 'nullable', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->where('school_id', tenant()->id)->ignore($this->userId)],
    'phone' => ['nullable', 'string', 'max:30', ...($this->parent ? [Rule::unique('users', 'phone')->where('school_id', tenant()->id)->ignore($this->userId)] : [])],
    'email' => ['nullable', 'email', 'max:255', ...($this->parent ? [Rule::unique('users', 'email')->where('school_id', tenant()->id)->ignore($this->userId)] : [])],
    'occupation' => ['nullable', 'string', 'max:100'],
    'address' => ['nullable', 'string', 'max:500'],
    'children' => ['array', 'max:10'],
    'children.*.id' => ['required', 'integer', 'distinct', Rule::exists('student_profiles', 'id')->where('school_id', tenant()->id)->whereNull('deleted_at')],
    'children.*.relation' => ['required', Rule::enum(GuardianRelation::class)],
    'children.*.is_primary' => ['boolean'],
])->messages(['username.regex' => __('Use letters, numbers, dots, dashes or underscores.')]);

$save = function (ParentRecords $records) {
    if ($this->done) {
        $this->closeModal();

        return;
    }

    $this->authorize($this->parent ? 'parents.edit' : 'parents.create');
    $data = $this->validate();

    $children = collect($data['children'])->mapWithKeys(fn (array $child) => [(int) $child['id'] => [
        'relation' => $child['relation'],
        'is_primary' => (bool) $child['is_primary'],
    ]])->all();

    try {
        if ($this->parent) {
            $records->update(ParentProfile::query()->with('user')->findOrFail($this->parent), $data, $children);
        } else {
            [, $credentials] = $records->create($data, $children, auth()->user());
            $this->credentials = $credentials?->toArray();
        }
    } catch (ActionFailed $e) {
        $this->addError('phone', $e->getMessage());

        return;
    }

    $this->refreshTables();

    if ($this->credentials) {
        $this->done = true;
        $this->toast(__('Parent added.'));

        return;
    }

    $this->closeModal();
    $this->toast($this->parent ? __('Parent saved.') : __('Parent linked to the existing account.'));
};

?>

<x-modal.form :title="$parent ? __('Edit parent') : __('New parent')" :subtitle="$done ? $name : null"
              :submit-label="$done ? __('Done') : __('Save')">
    @if ($done)
        <x-credentials :url="tenant()->loginUrl()" :username="$credentials['username']" :password="$credentials['password']" />
    @else
        <div class="row">
            <div class="col-sm-6"><x-form.input name="name" :label="__('Full name')" required maxlength="255" /></div>
            <div class="col-sm-6"><x-form.input name="username" :label="__('Username')" :required="(bool) $parent" maxlength="60" autocomplete="off"
                                                :hint="$parent ? null : __('Leave blank to make one from the name.')" /></div>
            <div class="col-sm-6"><x-form.input name="phone" type="tel" :label="__('Phone')" maxlength="30" :live="! $parent" /></div>
            <div class="col-sm-6"><x-form.input name="email" type="email" :label="__('Email')" maxlength="255" :live="! $parent" /></div>
            <div class="col-sm-6"><x-form.input name="occupation" :label="__('Occupation')" maxlength="100" /></div>
            <div class="col-sm-6"><x-form.input name="address" :label="__('Address')" maxlength="500" /></div>
        </div>

        @if ($this->match)
            <div @class(['alert py-2 px-3 fs-13', 'alert-danger' => $this->match->user_type === UserType::Student || $this->match->trashed(), 'alert-info' => ! ($this->match->user_type === UserType::Student || $this->match->trashed())])>
                @if ($this->match->trashed() || $this->match->user_type === UserType::Student)
                    {{ __('This phone or email already belongs to another account, so it can\'t be used for a parent.') }}
                @else
                    {{ __('This phone or email belongs to :name\'s existing account. Saving links the children to that account instead of creating a new one.', ['name' => $this->match->name]) }}
                @endif
            </div>
        @endif

        <h6 class="mb-2">{{ __('Children') }}</h6>
        @if ($children)
            <div class="table-responsive border rounded-2 mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col" class="ps-3">{{ __('Student') }}</th>
                            <th scope="col" style="width: 10rem">{{ __('Relation') }}</th>
                            <th scope="col" class="text-center">{{ __('Primary') }}</th>
                            <th scope="col" class="pe-3"><span class="visually-hidden">{{ __('Remove') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($children as $index => $child)
                            <tr wire:key="child-{{ $child['id'] }}">
                                <td class="ps-3">{{ $child['label'] }}</td>
                                <td>
                                    <select wire:model="children.{{ $index }}.relation" class="form-select form-select-sm" aria-label="{{ __('Relation') }}">
                                        @foreach (GuardianRelation::options() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" wire:model="children.{{ $index }}.is_primary" aria-label="{{ __('Primary contact for :name', ['name' => $child['label']]) }}">
                                </td>
                                <td class="text-end pe-3">
                                    <button type="button" class="btn btn-subtle-danger icon-btn-sm" wire:click="removeChild({{ $index }})" aria-label="{{ __('Unlink :name', ['name' => $child['label']]) }}"><i class="ri-close-line"></i></button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="position-relative">
            <div class="form-icon">
                <input type="search" class="form-control form-control-icon" autocomplete="off" wire:model.live.debounce.300ms="studentSearch"
                       placeholder="{{ __('Link a child: search by name or admission number…') }}" aria-label="{{ __('Search students') }}">
                <i class="ri-search-2-line text-muted"></i>
            </div>
            @if (mb_strlen(trim($studentSearch)) >= 2)
                <div class="list-group position-absolute start-0 end-0 shadow-sm z-3 mt-1">
                    @forelse ($this->studentResults as $result)
                        <button type="button" class="list-group-item list-group-item-action" wire:click="addChild({{ $result->id }})">
                            <span class="fw-medium">{{ $result->user?->name }}</span>
                            <small class="text-muted ms-1">{{ $result->admission_number }}{{ $result->placementLabel() ? ' · '.$result->placementLabel() : '' }}</small>
                        </button>
                    @empty
                        <div class="list-group-item text-muted fs-13">{{ __('No student matches.') }}</div>
                    @endforelse
                </div>
            @endif
        </div>
        <p class="form-text mb-0">{{ __('The primary contact is the guardian the school reaches first.') }}</p>
    @endif
</x-modal.form>
