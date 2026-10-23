<?php

/*
 * A staff member's profile: employment and personal details, what they
 * teach, attendance and leave. Shared by the School Admin and HR portals.
 */

use App\Enums\StaffAttendanceStatus;
use App\Models\AcademicYear;
use App\Models\StaffAttendance;
use App\Models\StaffProfile;
use App\Models\SubjectClass;
use App\Services\Access\AccessGuard;
use App\Support\Portal;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(fn () => $this->profile->user?->name ?? __('Staff member'));

state(['portal' => 'admin', 'staff' => null])->locked();
state(['tab' => 'overview']);

mount(function (int $staff) {
    $this->authorize('staff.view');

    $this->portal = Portal::current() === 'hr' ? 'hr' : 'admin';
    $this->staff = StaffProfile::query()->findOrFail($staff)->id;
});

on(['refresh-tables' => fn () => null]);

$profile = computed(fn () => StaffProfile::query()
    ->with(['user.roles', 'department', 'campus', 'classTeacherSections.schoolClass'])
    ->findOrFail($this->staff));

$teaching = computed(function () {
    $year = AcademicYear::query()->current()->value('id');

    return SubjectClass::query()
        ->with(['subject', 'schoolClass', 'section'])
        ->where('teacher_id', $this->staff)
        ->when($year, fn ($query) => $query->where('academic_year_id', $year))
        ->get()
        ->sortBy(fn (SubjectClass $allocation) => [$allocation->schoolClass?->numeric_level ?? 999, $allocation->schoolClass?->name, $allocation->section?->name])
        ->values();
});

// This month's register.
$attendance = computed(function () {
    $from = tenant()->today()->startOfMonth();
    $records = StaffAttendance::query()->where('staff_profile_id', $this->staff)->whereDate('date', '>=', $from)->latest('date')->get();

    return ['month' => $from, 'counts' => $records->countBy(fn ($record) => $record->status->value), 'records' => $records];
});

$leave = computed(fn () => $this->profile->leaveRequests()->with('leaveType')->latest('start_date')->limit(20)->get());

$canManage = computed(fn () => $this->profile->user && app(AccessGuard::class)->canManage(auth()->user(), $this->profile->user));

?>

@php
    $staff = $this->profile;
    $user = $staff->user;
    $school = tenant();
    $dateFormat = $school->setting('date_format');
    $bank = $staff->bank_details ?? [];
    $emergency = $staff->emergency_contact ?? [];
    $canSeePay = auth()->user()->canAny(['staff.edit', 'payroll.view']);
    $tabs = ['overview' => __('Overview'), 'teaching' => __('Teaching'), 'attendance' => __('Attendance'), 'leave' => __('Leave')];
@endphp

<div>
    <x-page-header :title="$user?->name ?? __('Staff member')"
                   :breadcrumbs="[Portal::label($portal) => route(Portal::dashboardRoute($portal)), __('Staff') => Portal::route($portal, 'staff.index'), ($user?->name ?? __('Staff member')) => null]">
        @if ($this->canManage)
            <x-slot:actions>
                @can('staff.edit')
                    <a href="{{ Portal::route($portal, 'staff.edit', $staff->id) }}" wire:navigate class="btn btn-primary"><i class="ri-pencil-line me-1"></i>{{ __('Edit') }}</a>
                @endcan
                @can('users.reset-password')
                    <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', { component: 'forms.reset-user-password', params: { user: {{ $staff->user_id }} } })">
                        <i class="ri-lock-password-line me-1"></i>{{ __('Reset password') }}
                    </button>
                @endcan
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-4">
            <span class="entity-avatar entity-avatar-lg rounded-circle">
                @if ($user?->avatarUrl())
                    <img src="{{ $user->avatarUrl() }}" alt="">
                @else
                    {{ $user?->initials() }}
                @endif
            </span>
            <div class="flex-grow-1 min-w-0">
                <h5 class="mb-1">{{ $user?->name }}
                    @if ($user)
                        <span class="badge bg-{{ $user->status->color() }}-subtle text-{{ $user->status->color() }} align-middle ms-1 fs-12">{{ $user->status->label() }}</span>
                    @endif
                </h5>
                <div class="d-flex flex-wrap gap-3 text-muted fs-13">
                    <span><i class="ri-hashtag me-1"></i>{{ $staff->employee_id }}</span>
                    @if ($staff->designation)<span><i class="ri-briefcase-line me-1"></i>{{ $staff->designation }}</span>@endif
                    @if ($staff->department)<span><i class="ri-organization-chart me-1"></i>{{ $staff->department->name }}</span>@endif
                    @if ($user)<span><i class="ri-shield-user-line me-1"></i>{{ $user->roles->pluck('name')->implode(', ') ?: __('No roles') }}</span>@endif
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs nav-tabs-bordered mb-4" role="tablist">
        @foreach ($tabs as $key => $label)
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['nav-link', 'active' => $tab === $key])>{{ $label }}</button>
            </li>
        @endforeach
    </ul>

    @if ($tab === 'overview')
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card mb-0 h-100">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Employment') }}</h5></div>
                    <div class="card-body">
                        <dl class="detail-list">
                            <dt>{{ __('Type') }}</dt><dd>{{ $user?->user_type->label() }}</dd>
                            <dt>{{ __('Joined') }}</dt><dd>{{ $staff->joining_date?->format($dateFormat) ?? '—' }}</dd>
                            <dt>{{ __('Campus') }}</dt><dd>{{ $staff->campus?->name ?? '—' }}</dd>
                            <dt>{{ __('Qualification') }}</dt><dd>{{ $staff->qualification ?: '—' }}</dd>
                            @if ($canSeePay)
                                <dt>{{ __('Monthly salary') }}</dt><dd>{{ $school->setting('currency_symbol') }}{{ number_format((float) $staff->salary, 2) }}</dd>
                                <dt>{{ __('Bank') }}</dt>
                                <dd>
                                    @if ($bank)
                                        {{ collect([$bank['bank_name'] ?? null, $bank['branch'] ?? null])->filter()->implode(', ') }}
                                        @if (! empty($bank['account_number']))
                                            <small class="d-block text-muted">{{ $bank['account_title'] ?? '' }} · ••••{{ Str::substr($bank['account_number'], -4) }}</small>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </dd>
                            @endif
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card mb-0 h-100">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Personal & contact') }}</h5></div>
                    <div class="card-body">
                        <dl class="detail-list">
                            <dt>{{ __('Username') }}</dt><dd><code>{{ $user?->username }}</code></dd>
                            <dt>{{ __('Phone') }}</dt><dd>{{ $user?->phone ?: '—' }}</dd>
                            <dt>{{ __('Email') }}</dt><dd>{{ $user?->email ?: '—' }}</dd>
                            <dt>{{ __('Gender') }}</dt><dd>{{ $staff->gender?->label() ?? '—' }}</dd>
                            <dt>{{ __('Date of birth') }}</dt><dd>{{ $staff->dob?->format($dateFormat) ?? '—' }}</dd>
                            <dt>{{ __('Address') }}</dt><dd class="text-pre-line">{{ $staff->address ?: '—' }}</dd>
                            <dt>{{ __('Emergency contact') }}</dt>
                            <dd>
                                @if ($emergency)
                                    {{ $emergency['name'] ?? '' }}@if (! empty($emergency['relation'])) ({{ $emergency['relation'] }})@endif
                                    <small class="d-block text-muted">{{ $emergency['phone'] ?? '' }}</small>
                                @else
                                    —
                                @endif
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($tab === 'teaching')
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card mb-0 h-100">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Class teacher of') }}</h5></div>
                    <ul class="list-group list-group-flush">
                        @forelse ($staff->classTeacherSections as $section)
                            <li class="list-group-item">{{ $section->schoolClass?->name }} · {{ $section->name }}</li>
                        @empty
                            <li class="list-group-item text-muted">{{ __('Not a class teacher.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card mb-0 h-100">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Subjects this year') }}</h5></div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <tbody>
                                @forelse ($this->teaching as $allocation)
                                    <tr>
                                        <td class="ps-4 fw-medium">{{ $allocation->subject?->name }}</td>
                                        <td>{{ $allocation->schoolClass?->name }}{{ $allocation->section ? ' · '.$allocation->section->name : '' }}</td>
                                        <td class="pe-4 text-muted text-end">{{ $allocation->periods_per_week ? trans_choice(':count period a week|:count periods a week', $allocation->periods_per_week) : '' }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted py-5">{{ __('No subjects allocated this year.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($tab === 'attendance')
        @php($stats = $this->attendance)
        <div class="row g-4 mb-4">
            @foreach (StaffAttendanceStatus::cases() as $status)
                <div class="col-6 col-md">
                    <div class="card mb-0">
                        <div class="card-body">
                            <span class="text-muted fs-13">{{ $status->label() }}</span>
                            <div class="fs-4 fw-semibold text-{{ $status->color() }}">{{ $stats['counts'][$status->value] ?? 0 }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="card mb-0">
            <div class="card-header"><h5 class="card-title mb-0">{{ $stats['month']->translatedFormat('F Y') }}</h5></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <tbody>
                        @forelse ($stats['records'] as $record)
                            <tr>
                                <td class="ps-4">{{ $record->date->format($dateFormat) }} <small class="text-muted ms-1">{{ $record->date->translatedFormat('l') }}</small></td>
                                <td><span class="badge bg-{{ $record->status->color() }}-subtle text-{{ $record->status->color() }}">{{ $record->status->label() }}</span></td>
                                <td class="text-muted">{{ $record->check_in ? substr($record->check_in, 0, 5) : '' }}{{ $record->check_out ? ' – '.substr($record->check_out, 0, 5) : '' }}</td>
                                <td class="pe-4 text-muted">{{ $record->remarks }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-5">{{ __('No attendance recorded this month.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card mb-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col" class="ps-4">{{ __('Dates') }}</th>
                            <th scope="col">{{ __('Type') }}</th>
                            <th scope="col">{{ __('Days') }}</th>
                            <th scope="col" class="pe-4">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->leave as $request)
                            <tr>
                                <td class="ps-4 text-nowrap">{{ $request->start_date->format($dateFormat) }} – {{ $request->end_date->format($dateFormat) }}</td>
                                <td>{{ $request->leaveType?->name }}</td>
                                <td>{{ (float) $request->days }}</td>
                                <td class="pe-4"><span class="badge bg-{{ $request->status->color() }}-subtle text-{{ $request->status->color() }}">{{ $request->status->label() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-5">{{ __('No leave requests.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
