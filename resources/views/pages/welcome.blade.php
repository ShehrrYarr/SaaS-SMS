<?php

/*
 * The public landing page: what the product does, one-click entry to the
 * demo school as any role (when DEMO_ENABLED), plans, and the box that takes
 * a school's staff, students and parents to their school's sign-in page.
 */

use App\Enums\AttendanceStatus;
use App\Models\LibraryBook;
use App\Models\Plan;
use App\Models\School;
use App\Models\Section;
use App\Models\StaffProfile;
use App\Models\StudentAttendance;
use App\Models\StudentProfile;
use App\Support\Billing\PlanFeatures;
use App\Support\Demo;

use function Livewire\Volt\{layout, protect, rules, state, title, with};

layout('layouts.landing');
title(__('Cloud school management'));

state(['school' => '']);

rules(['school' => ['required', 'string', 'max:60']]);

$go = function () {
    $this->validate();

    $slug = strtolower(trim($this->school));
    $school = School::query()->where('slug', $slug)->first();

    if ($school === null || $school->isSuspended()) {
        $this->addError('school', __('We couldn\'t find an active school with that address.'));

        return;
    }

    $this->redirect(route('tenant.login', ['school' => $school->slug]));
};

/*
 * The demo school's name and a few live numbers, or null when there is no
 * demo (or it is being rebuilt).
 */
$demoSchool = protect(function (): ?array {
    $school = Demo::school();

    if ($school === null || $school->isSuspended()) {
        return null;
    }

    return tenancy()->run($school, function () use ($school) {
        $present = [AttendanceStatus::Present->value, AttendanceStatus::Late->value];

        // Share of students present on each of the last ten register days.
        $days = StudentAttendance::query()
            ->whereDate('date', '<=', tenant()->today()->toDateString())
            ->selectRaw('date, sum(case when status in (?, ?) then 1 else 0 end) as present, count(*) as total', $present)
            ->groupBy('date')
            ->orderByDesc('date')
            ->limit(10)
            ->toBase()
            ->get()
            ->reverse()
            ->map(fn ($day) => (int) round(100 * $day->present / max(1, $day->total)))
            ->values()
            ->all();

        return [
            'name' => $school->name,
            'students' => StudentProfile::query()->active()->count(),
            'staff' => StaffProfile::query()->count(),
            'sections' => Section::query()->count(),
            'books' => LibraryBook::query()->count(),
            'attendance' => $days,
        ];
    });
});

with(fn () => [
    'demo' => Demo::enabled() ? $this->demoSchool() : null,
    'accounts' => Demo::accounts(),
    'plans' => Plan::query()->active()->ordered()->get(),
    'features' => PlanFeatures::options(),
]);

?>

@php
    $modules = [
        ['ri-user-add-line', __('Students & admissions'), [__('Admissions with automatic admission numbers'), __('Guardians, siblings and medical notes'), __('CSV imports and yearly promotion')]],
        ['ri-calendar-check-line', __('Attendance & leave'), [__('Daily class registers by class teachers'), __('Student and staff leave with approvals'), __('Leave balances per staff member')]],
        ['ri-time-line', __('Timetable'), [__('Campuses, rooms and bell schedule'), __('Weekly timetable with clash checks'), __('Each teacher\'s and student\'s own week')]],
        ['ri-file-list-3-line', __('Examinations & results'), [__('Exam schedules and date sheets'), __('Marks entry, grade scales and remarks'), __('Report cards as PDF, published to families')]],
        ['ri-draft-line', __('Homework'), [__('Assignments with attachments'), __('Submissions from the student portal'), __('Marking and feedback')]],
        ['ri-hand-coin-line', __('Fees & invoices'), [__('Fee structures, discounts and sibling rules'), __('Monthly invoice runs and late fees'), __('Invoices and receipts as PDF')]],
        ['ri-line-chart-line', __('Finance'), [__('Collections by cash, bank or cheque'), __('Expenses and income ledger'), __('Finance dashboard and reports')]],
        ['ri-team-line', __('HR & staff'), [__('Staff records and departments'), __('Staff attendance'), __('Leave types, balances and approvals')]],
        ['ri-money-dollar-box-line', __('Payroll'), [__('Monthly payroll with allowances and deductions'), __('Advance recovery and absence deductions'), __('Salary slips as PDF')]],
        ['ri-book-open-line', __('Library'), [__('Catalogue with copies per title'), __('Issues, returns and renewals'), __('Overdue reminders and fines')]],
        ['ri-bus-2-line', __('Transport'), [__('Vehicles, routes and stops'), __('Bus seats for students'), __('Route changes with history')]],
        ['ri-hotel-bed-line', __('Hostel'), [__('Hostels, rooms and beds'), __('Allocations, moves and check-outs'), __('Boarders by room')]],
        ['ri-megaphone-line', __('Notices & notifications'), [__('Notices to the whole school or chosen classes'), __('Pinned, scheduled and expiring notices'), __('In-app notifications for every account')]],
    ];

    $steps = [
        ['ri-settings-3-line', __('Set up'), __('Campuses, classes, sections, subjects and the bell schedule, in an afternoon.')],
        ['ri-user-add-line', __('Admit'), __('Admit students with their guardians, fee plan, bus seat and hostel bed.')],
        ['ri-time-line', __('Plan the week'), __('Assign teachers to subjects and build every section\'s timetable.')],
        ['ri-calendar-check-line', __('Teach'), __('Teachers mark registers and set homework; parents see it the same day.')],
        ['ri-file-list-3-line', __('Assess'), __('Schedule exams, enter marks and publish report cards.')],
        ['ri-hand-coin-line', __('Collect & pay'), __('Invoice fees, take payments, track expenses and run payroll.')],
    ];

    $firstAccount = array_key_first($accounts);
    $bars = $demo['attendance'] ?? [];
@endphp

<div>
    {{-- Hero --}}
    <section class="landing-hero">
        <div class="container">
            <div class="row align-items-center gy-5">
                <div class="col-lg-6">
                    <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2 mb-4">
                        <i class="ri-cloud-line me-1"></i>{{ __('Cloud school management · Multi-school SaaS') }}
                    </span>
                    <h1 class="landing-title">{{ __('Run your whole school from one place') }}</h1>
                    <p class="lead text-muted mt-4">
                        {{ __('Admissions to report cards: attendance, timetable, exams, homework, fees, finance, HR, payroll, library, transport and hostel, with portals for teachers, students and parents.') }}
                    </p>

                    @if ($demo)
                        <div class="d-flex flex-wrap gap-2 mt-5">
                            <form method="POST" action="{{ route('demo.enter', $firstAccount) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-lg rounded-2">
                                    <i class="ri-school-line me-1"></i>{{ __('Try the demo school') }}
                                    <span class="opacity-75 fs-14 ms-1">{{ __('as :role', ['role' => __($accounts[$firstAccount]['label'])]) }}</span>
                                </button>
                            </form>
                            <a href="#demo" class="btn btn-light btn-lg rounded-2"><i class="ri-user-line me-1"></i>{{ __('Try another role') }}</a>
                        </div>
                        <p class="text-muted fs-13 mt-3 mb-0">
                            <i class="ri-shield-check-line text-success me-1"></i>{{ __('No sign-up or password. Explore :school with a term of realistic data; it resets every night.', ['school' => $demo['name']]) }}
                        </p>
                    @else
                        <div class="d-flex flex-wrap gap-2 mt-5">
                            <a href="#features" class="btn btn-primary btn-lg rounded-2">{{ __('See the features') }}</a>
                            <a href="#sign-in" class="btn btn-light btn-lg rounded-2">{{ __('School sign in') }}</a>
                        </div>
                    @endif
                </div>

                <div class="col-lg-6">
                    @if ($demo)
                    <div class="landing-mockup card border-0 shadow-lg mb-0">
                        <div class="card-header bg-transparent d-flex align-items-center gap-2 py-3">
                            <span class="mockup-dot"></span><span class="mockup-dot"></span><span class="mockup-dot"></span>
                            <span class="ms-2 text-muted fs-13 text-truncate">{{ $demo['name'] }} · {{ __('School Admin') }}</span>
                            <span class="badge bg-success-subtle text-success ms-auto">{{ __('Live') }}</span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                @foreach ([
                                    [__('Students'), $demo['students'], 'ri-graduation-cap-line', 'primary'],
                                    [__('Staff'), $demo['staff'], 'ri-team-line', 'success'],
                                    [__('Sections'), $demo['sections'], 'ri-layout-grid-line', 'warning'],
                                    [__('Library titles'), $demo['books'], 'ri-book-open-line', 'info'],
                                ] as [$label, $value, $icon, $tone])
                                    <div class="col-6">
                                        <div class="border rounded-3 p-3 h-100">
                                            <div class="d-flex align-items-center gap-2 text-muted fs-13"><i class="{{ $icon }} text-{{ $tone }}"></i>{{ $label }}</div>
                                            <div class="fs-4 fw-semibold mt-1">{{ number_format($value) }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @if ($bars)
                            <div class="border rounded-3 p-3 mt-3">
                                <div class="d-flex justify-content-between text-muted fs-13">
                                    <span>{{ __('Attendance · last :count school days', ['count' => count($bars)]) }}</span>
                                    <span class="fw-semibold text-body">{{ end($bars) }}%</span>
                                </div>
                                <div class="mockup-bars mt-3" aria-hidden="true">
                                    @foreach ($bars as $rate)
                                        <span style="height: {{ max(8, ($rate - 50) * 2) }}%"></span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                    @else
                    <div class="row g-3">
                        @foreach ([
                            ['ri-shield-user-line', __('School Admin')], ['ri-presentation-line', __('Teacher')], ['ri-team-line', __('HR')],
                            ['ri-calculator-line', __('Accounts')], ['ri-graduation-cap-line', __('Student')], ['ri-parent-line', __('Parent')],
                        ] as [$icon, $portal])
                            <div class="col-6 col-md-4">
                                <div class="card mb-0 text-center"><div class="card-body">
                                    <span class="landing-icon mx-auto"><i class="{{ $icon }}"></i></span>
                                    <div class="fw-medium mt-2">{{ $portal }}</div>
                                </div></div>
                            </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- At a glance --}}
    <section class="py-5 border-top border-bottom">
        <div class="container">
            <div class="row text-center gy-4">
                @foreach ([
                    [count($modules), __('integrated modules')],
                    [6, __('portals: admin, teacher, HR, accounts, student, parent')],
                    ['100%', __('data isolation per school')],
                    [0, __('installs: it runs in the browser')],
                ] as [$value, $label])
                    <div class="col-6 col-lg-3">
                        <div class="landing-stat">{{ $value }}</div>
                        <div class="text-muted fs-14">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Live demo --}}
    @if (Demo::enabled())
        <section id="demo" class="landing-section">
            <div class="container">
                <div class="landing-heading">
                    <span class="landing-eyebrow">{{ __('Live demo') }}</span>
                    <h2>{{ __('Try every role in one click') }}</h2>
                    @if ($demo)
                        <p class="text-muted">
                            {{ __('Step into :school, a working school with :students students, :staff staff and :sections class sections, mid-way through its year: registers, exams, fees, payroll and more. Each role sees only what its permissions allow.', ['school' => $demo['name'], 'students' => $demo['students'], 'staff' => $demo['staff'], 'sections' => $demo['sections']]) }}
                        </p>
                    @endif
                </div>

                @if (session('demo-unavailable') || ! $demo)
                    <div class="alert alert-warning d-flex gap-2 mx-auto" style="max-width: 40rem">
                        <i class="ri-time-line fs-5 lh-1"></i>{{ session('demo-unavailable') ?? __('The demo school is being refreshed. Please try again in a few minutes.') }}
                    </div>
                @endif

                @if ($demo)
                    <div class="row g-4">
                        @foreach ($accounts as $key => $account)
                            <div class="col-sm-6 col-lg-4 col-xl-3">
                                <form method="POST" action="{{ route('demo.enter', $key) }}" class="h-100">
                                    @csrf
                                    <button type="submit" @class(['landing-role card h-100 w-100 text-start mb-0', 'is-recommended' => $key === $firstAccount])>
                                        <span class="card-body d-flex flex-column">
                                            <span class="d-flex align-items-start justify-content-between">
                                                <span class="landing-icon"><i class="{{ $account['icon'] }}"></i></span>
                                                @if ($key === $firstAccount)
                                                    <span class="badge bg-primary-subtle text-primary">{{ __('Recommended') }}</span>
                                                @endif
                                            </span>
                                            <span class="fw-semibold fs-16 mt-3">{{ __($account['label']) }}</span>
                                            <span class="text-muted fs-13 mt-1 flex-grow-1">{{ __($account['description']) }}</span>
                                            <span class="text-primary fw-medium fs-14 mt-3">{{ __('Enter as :role', ['role' => __($account['label'])]) }}<i class="ri-arrow-right-line ms-1"></i></span>
                                        </span>
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>

                    <p class="text-muted text-center fs-13 mt-4 mb-0">
                        <i class="ri-information-line me-1"></i>{{ __('The demo is shared by every visitor. Changes are welcome and are wiped every night; sign-ins and the school\'s name, logo and settings are locked.') }}
                    </p>
                @endif
            </div>
        </section>
    @endif

    {{-- Modules --}}
    <section id="features" class="landing-section landing-band">
        <div class="container">
            <div class="landing-heading">
                <span class="landing-eyebrow">{{ __('Everything in one place') }}</span>
                <h2>{{ __('Built for every office in the school') }}</h2>
                <p class="text-muted">{{ __('Every module shares one student record, so an admission reaches the class register, the fee run, the bus list and the parent portal without retyping.') }}</p>
            </div>

            <div class="row g-4">
                @foreach ($modules as [$icon, $name, $points])
                    <div class="col-sm-6 col-lg-4 col-xl-3">
                        <div class="card h-100 mb-0 landing-module">
                            <div class="card-body">
                                <span class="landing-icon"><i class="{{ $icon }}"></i></span>
                                <h5 class="mt-3 mb-3 fs-16">{{ $name }}</h5>
                                <ul class="list-unstyled d-grid gap-2 mb-0 fs-13 text-muted">
                                    @foreach ($points as $point)
                                        <li class="d-flex gap-2"><i class="ri-check-line text-success"></i>{{ $point }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- How it works --}}
    <section id="how-it-works" class="landing-section">
        <div class="container">
            <div class="landing-heading">
                <span class="landing-eyebrow">{{ __('How it works') }}</span>
                <h2>{{ __('One connected school year') }}</h2>
            </div>

            <div class="row g-4">
                @foreach ($steps as $index => [$icon, $name, $text])
                    <div class="col-sm-6 col-lg-4 col-xl-2">
                        <div class="landing-step h-100">
                            <span class="landing-step-number">{{ $index + 1 }}</span>
                            <h6 class="mt-3 mb-2"><i class="{{ $icon }} text-primary me-1"></i>{{ $name }}</h6>
                            <p class="text-muted fs-13 mb-0">{{ $text }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Groups and security --}}
    <section class="landing-section landing-band">
        <div class="container">
            <div class="row g-4">
                @foreach ([
                    [__('For school groups & providers'), __('One platform, many schools'), [
                        __('Each school gets its own address, name, logo, currency and timezone'),
                        __('Subscription plans unlock modules; upgrade any time'),
                        __('Platform console for onboarding, plans, billing and suspensions'),
                        __('Custom roles with per-module permissions'),
                        __('Works on desktop, tablet and phone, with nothing to install'),
                    ]],
                    [__('Security & trust'), __('School data stays where it belongs'), [
                        __('Strict per-school isolation on every query, upload and report'),
                        __('Activity log of who changed what, and when'),
                        __('Role-based access: staff only see what their job needs'),
                        __('Private storage for documents and attachments'),
                        __('New accounts must choose their own password'),
                    ]],
                ] as [$eyebrow, $heading, $points])
                    <div class="col-lg-6">
                        <div class="card h-100 mb-0">
                            <div class="card-body p-4 p-lg-5">
                                <span class="landing-eyebrow">{{ $eyebrow }}</span>
                                <h3 class="mt-2 mb-4">{{ $heading }}</h3>
                                <ul class="list-unstyled d-grid gap-3 mb-0">
                                    @foreach ($points as $point)
                                        <li class="d-flex gap-2"><i class="ri-checkbox-circle-fill text-primary"></i>{{ $point }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Pricing --}}
    @if ($plans->isNotEmpty())
        <section id="pricing" class="landing-section">
            <div class="container">
                <div class="landing-heading">
                    <span class="landing-eyebrow">{{ __('Pricing') }}</span>
                    <h2>{{ __('Simple plans that grow with you') }}</h2>
                    <p class="text-muted">{{ __('Students, attendance, timetable, notices and the student and parent portals are in every plan.') }}</p>
                </div>

                <div class="row g-4 justify-content-center">
                    @foreach ($plans as $plan)
                        <div class="col-md-6 col-xl-4">
                            <div @class(['card h-100 mb-0 landing-plan', 'is-featured' => $loop->index === 1 && $plans->count() > 2])>
                                <div class="card-body p-4 d-flex flex-column">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <h4 class="mb-0">{{ $plan->name }}</h4>
                                        @if ($loop->index === 1 && $plans->count() > 2)
                                            <span class="badge bg-primary">{{ __('Most popular') }}</span>
                                        @endif
                                    </div>
                                    @if ($plan->description)
                                        <p class="text-muted fs-14 mt-2 mb-0">{{ $plan->description }}</p>
                                    @endif
                                    <div class="mt-4">
                                        <span class="fs-2 fw-bold">{{ $plan->formattedPrice() }}</span>
                                        <span class="text-muted">{{ $plan->billing_cycle->per() }}</span>
                                    </div>
                                    <div class="text-muted fs-13 mt-1">
                                        {{ $plan->max_students ? __('Up to :count students', ['count' => number_format($plan->max_students)]) : __('Unlimited students') }}
                                        · {{ $plan->max_staff ? __(':count staff', ['count' => number_format($plan->max_staff)]) : __('unlimited staff') }}
                                        @if ($plan->trial_days)
                                            · {{ __(':days-day free trial', ['days' => $plan->trial_days]) }}
                                        @endif
                                    </div>

                                    <ul class="list-unstyled d-grid gap-2 mt-4 mb-0 fs-14 flex-grow-1">
                                        @foreach ($features as $key => $label)
                                            <li @class(['d-flex gap-2', 'text-muted opacity-50' => ! $plan->includesFeature($key)])>
                                                <i @class(['ri-check-line text-success' => $plan->includesFeature($key), 'ri-close-line' => ! $plan->includesFeature($key)])></i>{{ $label }}
                                            </li>
                                        @endforeach
                                    </ul>

                                    @if ($demo)
                                        <a href="#demo" @class(['btn rounded-2 mt-4', 'btn-primary' => $loop->index === 1, 'btn-light' => $loop->index !== 1])>{{ __('Try it in the demo') }}</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Sign in --}}
    <section id="sign-in" class="landing-section landing-band">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="card mb-0 shadow-sm">
                        <div class="card-body p-4 p-lg-5">
                            <h3 class="mb-2">{{ __('Already using :app?', ['app' => config('app.name')]) }}</h3>
                            <p class="text-muted mb-4">{{ __('Enter your school\'s address to go to its sign-in page. Staff, students and parents all sign in there.') }}</p>

                            <form wire:submit="go" class="form-custom" novalidate>
                                <label class="form-label" for="school">{{ __('School address') }}</label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text text-muted">{{ preg_replace('#^https?://#', '', url('/')) }}/</span>
                                    <input wire:model="school" type="text" id="school" placeholder="{{ __('your-school') }}" autocomplete="off" autocapitalize="none"
                                           @class(['form-control', 'is-invalid' => $errors->has('school')])>
                                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="go">
                                        <span wire:loading.remove wire:target="go">{{ __('Continue') }}</span>
                                        <span wire:loading wire:target="go"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span></span>
                                    </button>
                                    @error('school')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
