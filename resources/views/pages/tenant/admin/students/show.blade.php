<?php

/*
 * A student's profile: details, guardians, enrollment history, attendance
 * and leave.
 */

use App\Enums\AttendanceStatus;
use App\Models\StudentAttendance;
use App\Models\StudentProfile;
use App\Services\Access\AccessGuard;
use App\Services\Attendance\SchoolCalendar;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(fn () => $this->profile->user?->name ?? __('Student'));

state(['student' => null])->locked();
state(['tab' => 'overview']);

mount(function (int $student) {
    $this->authorize('students.view');
    $this->student = StudentProfile::query()->findOrFail($student)->id;
});

on(['refresh-tables' => fn () => null]);

$profile = computed(fn () => StudentProfile::query()
    ->with(['user', 'schoolClass', 'section.classTeacher.user', 'parents.user', 'enrollments' => fn ($query) => $query->with(['academicYear', 'schoolClass', 'section'])->latest('enrolled_on')->latest('id')])
    ->findOrFail($this->student));

$year = computed(fn () => app(SchoolCalendar::class)->yearFor(tenant()->today()));

// This academic year's attendance: counts per status and the latest records.
$attendance = computed(function () {
    $query = StudentAttendance::query()->where('student_profile_id', $this->student)
        ->when($this->year, fn ($query) => $query->where('academic_year_id', $this->year->id));

    $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->toBase()->pluck('total', 'status')->map(fn ($total) => (int) $total);
    $marked = $counts->sum();
    $attended = ($counts[AttendanceStatus::Present->value] ?? 0) + ($counts[AttendanceStatus::Late->value] ?? 0);

    return [
        'counts' => $counts,
        'marked' => $marked,
        'rate' => $marked ? round($attended / $marked * 100, 1) : null,
        'recent' => $query->latest('date')->limit(15)->get(),
    ];
});

$leave = computed(fn () => $this->profile->leaveRequests()->with('leaveType')->latest('start_date')->limit(20)->get());

$canManageAccount = computed(fn () => $this->profile->user
    && auth()->user()->can('users.reset-password')
    && app(AccessGuard::class)->canManage(auth()->user(), $this->profile->user));

?>

@php
    $student = $this->profile;
    $user = $student->user;
    $school = tenant();
    $dateFormat = $school->setting('date_format');
    $tabs = [
        'overview' => __('Overview'),
        'guardians' => __('Guardians').' ('.$student->parents->count().')',
        'enrollment' => __('Enrollment history'),
        'attendance' => __('Attendance'),
        'leave' => __('Leave'),
    ];
@endphp

<div>
    <x-page-header :title="$user?->name ?? __('Student')"
                   :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Students') => route('admin.students.index'), ($user?->name ?? __('Student')) => null]">
        <x-slot:actions>
            @can('students.edit')
                <a href="{{ route('admin.students.edit', $student->id) }}" wire:navigate class="btn btn-primary"><i class="ri-pencil-line me-1"></i>{{ __('Edit') }}</a>
                <div class="dropdown">
                    <button type="button" class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">{{ __('More') }}</button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <button type="button" class="dropdown-item" wire:click="$dispatch('open-modal', { component: 'forms.student-placement', params: { student: {{ $student->id }} } })">
                            <i class="ri-arrow-left-right-line me-2"></i>{{ __('Change class or section') }}
                        </button>
                        <button type="button" class="dropdown-item" wire:click="$dispatch('open-modal', { component: 'forms.student-status', params: { student: {{ $student->id }} } })">
                            <i class="ri-user-settings-line me-2"></i>{{ __('Change status') }}
                        </button>
                        @if ($this->canManageAccount)
                            <button type="button" class="dropdown-item text-warning" wire:click="$dispatch('open-modal', { component: 'forms.reset-user-password', params: { user: {{ $student->user_id }} } })">
                                <i class="ri-lock-password-line me-2"></i>{{ __('Reset password') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endcan
        </x-slot:actions>
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
                    <span class="badge bg-{{ $student->status->color() }}-subtle text-{{ $student->status->color() }} align-middle ms-1 fs-12">{{ $student->status->label() }}</span>
                </h5>
                <div class="d-flex flex-wrap gap-3 text-muted fs-13">
                    <span><i class="ri-hashtag me-1"></i>{{ $student->admission_number }}</span>
                    <span><i class="ri-building-2-line me-1"></i>{{ $student->placementLabel() ?? __('No class') }}</span>
                    @if ($student->roll_number)
                        <span><i class="ri-list-ordered me-1"></i>{{ __('Roll :roll', ['roll' => $student->roll_number]) }}</span>
                    @endif
                    @if ($student->section?->classTeacher?->user)
                        <span><i class="ri-presentation-line me-1"></i>{{ __('Class teacher: :name', ['name' => $student->section->classTeacher->user->name]) }}</span>
                    @endif
                </div>
            </div>
            @if ($this->attendance['rate'] !== null)
                <div class="text-center px-3">
                    <div class="fs-4 fw-semibold">{{ $this->attendance['rate'] }}%</div>
                    <small class="text-muted">{{ __('Attendance this year') }}</small>
                </div>
            @endif
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
            <div class="col-lg-7">
                <div class="card mb-0 h-100">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Details') }}</h5></div>
                    <div class="card-body">
                        <dl class="detail-list">
                            <dt>{{ __('Gender') }}</dt><dd>{{ $student->gender?->label() ?? '—' }}</dd>
                            <dt>{{ __('Date of birth') }}</dt>
                            <dd>{{ $student->dob ? $student->dob->format($dateFormat).' ('.__(':years years', ['years' => $student->dob->age]).')' : '—' }}</dd>
                            <dt>{{ __('Blood group') }}</dt><dd>{{ $student->blood_group ?: '—' }}</dd>
                            <dt>{{ __('Admitted on') }}</dt><dd>{{ $student->admission_date?->format($dateFormat) ?? '—' }}</dd>
                            <dt>{{ __('Previous school') }}</dt><dd>{{ $student->previous_school ?: '—' }}</dd>
                            <dt>{{ __('Address') }}</dt><dd class="text-pre-line">{{ $student->address ?: '—' }}</dd>
                            <dt>{{ __('Medical notes') }}</dt><dd class="text-pre-line">{{ $student->medical_notes ?: '—' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card mb-0 h-100">
                    <div class="card-header"><h5 class="card-title mb-0">{{ __('Sign-in account') }}</h5></div>
                    <div class="card-body">
                        <dl class="detail-list">
                            <dt>{{ __('Username') }}</dt><dd><code>{{ $user?->username }}</code></dd>
                            <dt>{{ __('Email') }}</dt><dd>{{ $user?->email ?: '—' }}</dd>
                            <dt>{{ __('Phone') }}</dt><dd>{{ $user?->phone ?: '—' }}</dd>
                            <dt>{{ __('Account') }}</dt>
                            <dd>@if ($user)<span class="badge bg-{{ $user->status->color() }}-subtle text-{{ $user->status->color() }}">{{ $user->status->label() }}</span>@endif</dd>
                            <dt>{{ __('Last sign-in') }}</dt>
                            <dd>{{ $user?->last_login_at ? $school->localTime($user->last_login_at)->format($dateFormat.', '.$school->setting('time_format')) : __('Never') }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($tab === 'guardians')
        <div class="row g-4">
            @forelse ($student->parents as $parent)
                <div class="col-md-6 col-xl-4">
                    <div class="card mb-0 h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <span class="entity-avatar rounded-circle">{{ $parent->user?->initials() }}</span>
                                <div class="min-w-0 flex-grow-1">
                                    <h6 class="mb-0 text-truncate">{{ $parent->user?->name }}</h6>
                                    <small class="text-muted">{{ $parent->pivot->relation?->label() }}</small>
                                </div>
                                @if ($parent->pivot->is_primary)
                                    <span class="badge bg-primary-subtle text-primary">{{ __('Primary') }}</span>
                                @endif
                            </div>
                            <dl class="detail-list mb-0">
                                <dt>{{ __('Phone') }}</dt><dd>{{ $parent->user?->phone ?: '—' }}</dd>
                                <dt>{{ __('Email') }}</dt><dd>{{ $parent->user?->email ?: '—' }}</dd>
                                <dt>{{ __('Occupation') }}</dt><dd>{{ $parent->occupation ?: '—' }}</dd>
                                <dt>{{ __('Username') }}</dt><dd><code>{{ $parent->user?->username }}</code></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="card mb-0">
                        <x-empty-state icon="ri-parent-line" :title="__('No guardians linked')" :message="__('Add parents or guardians from Edit.')" />
                    </div>
                </div>
            @endforelse
        </div>
    @elseif ($tab === 'enrollment')
        <div class="card mb-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light bg-opacity-30">
                        <tr>
                            <th scope="col" class="ps-4">{{ __('Academic year') }}</th>
                            <th scope="col">{{ __('Class') }}</th>
                            <th scope="col">{{ __('Roll no.') }}</th>
                            <th scope="col">{{ __('From') }}</th>
                            <th scope="col">{{ __('Until') }}</th>
                            <th scope="col" class="pe-4">{{ __('Outcome') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($student->enrollments as $enrollment)
                            <tr>
                                <td class="ps-4 fw-medium">{{ $enrollment->academicYear?->name }}</td>
                                <td>{{ $enrollment->schoolClass?->name }}{{ $enrollment->section ? ' · '.$enrollment->section->name : '' }}</td>
                                <td>{{ $enrollment->roll_number ?: '—' }}</td>
                                <td>{{ $enrollment->enrolled_on?->format($dateFormat) ?? '—' }}</td>
                                <td>{{ $enrollment->left_on?->format($dateFormat) ?? '—' }}</td>
                                <td class="pe-4"><span class="badge bg-{{ $enrollment->status->color() }}-subtle text-{{ $enrollment->status->color() }}">{{ $enrollment->status->label() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-5">{{ __('No enrollments recorded.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'attendance')
        @php($stats = $this->attendance)
        <div class="row g-4 mb-4">
            @foreach (AttendanceStatus::cases() as $status)
                <div class="col-6 col-lg-3">
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
            <div class="card-header hstack gap-2">
                <h5 class="card-title mb-0 flex-grow-1">{{ __('Recent days') }}</h5>
                @if ($this->year)<span class="badge bg-light text-muted border">{{ $this->year->name }}</span>@endif
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <tbody>
                        @forelse ($stats['recent'] as $record)
                            <tr>
                                <td class="ps-4">{{ $record->date->format($dateFormat) }} <small class="text-muted ms-1">{{ $record->date->translatedFormat('l') }}</small></td>
                                <td><span class="badge bg-{{ $record->status->color() }}-subtle text-{{ $record->status->color() }}">{{ $record->status->label() }}</span></td>
                                <td class="pe-4 text-muted">{{ $record->remarks }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-5">{{ __('No attendance recorded this year.') }}</td></tr>
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
                            <th scope="col">{{ __('Reason') }}</th>
                            <th scope="col" class="pe-4">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->leave as $request)
                            <tr>
                                <td class="ps-4 text-nowrap">{{ $request->start_date->format($dateFormat) }} – {{ $request->end_date->format($dateFormat) }}</td>
                                <td>{{ $request->leaveType?->name }}</td>
                                <td>{{ (float) $request->days }}</td>
                                <td class="text-break">{{ Str::limit($request->reason, 80) }}</td>
                                <td class="pe-4"><span class="badge bg-{{ $request->status->color() }}-subtle text-{{ $request->status->color() }}">{{ $request->status->label() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-5">{{ __('No leave requests.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
