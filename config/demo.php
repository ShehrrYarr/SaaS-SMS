<?php

/*
|--------------------------------------------------------------------------
| Public Demo
|--------------------------------------------------------------------------
|
| On a demo server the landing page signs visitors straight in to the demo
| school (built by `php artisan demo:seed`) as one of the accounts below.
| Visitors may change the school's data, but not its sign-ins (passwords,
| usernames, the showcased accounts) or its name, logo and settings; the
| school is rebuilt every night at `reset_at` (`reset_timezone`).
|
*/

return [

    'enabled' => (bool) env('DEMO_ENABLED', false),

    'school' => env('DEMO_SCHOOL', 'greenfield'),

    // The demo accounts' password; it must keep working, so it can't be changed.
    'password' => env('DEMO_PASSWORD', 'password'),

    'reset_at' => env('DEMO_RESET_AT', '03:00'),

    'reset_timezone' => env('DEMO_RESET_TIMEZONE', 'Asia/Karachi'),

    // The "Enter as" cards on the landing page, in order (key => account).
    'accounts' => [
        'admin' => [
            'username' => 'admin',
            'label' => 'School Admin',
            'description' => 'Every module: admissions, classes, timetable, exams, fees, roles and settings',
            'icon' => 'ri-shield-user-line',
        ],
        'teacher' => [
            'username' => 'sana.malik',
            'label' => 'Class Teacher',
            'description' => 'Timetable, attendance register, marks entry, homework and leave approvals',
            'icon' => 'ri-presentation-line',
        ],
        'exam-coordinator' => [
            'username' => 'faisal.mahmood',
            'label' => 'Exam Coordinator',
            'description' => 'A custom role: exam schedules, marks and report cards only',
            'icon' => 'ri-file-list-3-line',
        ],
        'hr' => [
            'username' => 'hina.hr',
            'label' => 'HR Manager',
            'description' => 'Staff records, staff attendance, leave and monthly payroll',
            'icon' => 'ri-team-line',
        ],
        'accountant' => [
            'username' => 'omar.accounts',
            'label' => 'Accountant',
            'description' => 'Fee invoices, collections, expenses, income and finance reports',
            'icon' => 'ri-calculator-line',
        ],
        'librarian' => [
            'username' => 'laila.library',
            'label' => 'Librarian',
            'description' => 'Book catalogue, issues, returns, renewals and fines',
            'icon' => 'ri-book-open-line',
        ],
        'transport' => [
            'username' => 'bilal.transport',
            'label' => 'Transport Manager',
            'description' => 'Vehicles, routes, stops and bus seats',
            'icon' => 'ri-bus-2-line',
        ],
        'hostel' => [
            'username' => 'nadia.hostel',
            'label' => 'Hostel Warden',
            'description' => 'Hostels, rooms, allocations and boarders',
            'icon' => 'ri-hotel-bed-line',
        ],
        'student' => [
            'username' => 'ayesha.khan',
            'label' => 'Student',
            'description' => 'Timetable, attendance, homework, results, fees and library',
            'icon' => 'ri-graduation-cap-line',
        ],
        'parent' => [
            'username' => 'imran.khan',
            'label' => 'Parent',
            'description' => 'Two children: attendance, homework, results, fee invoices and notices',
            'icon' => 'ri-parent-line',
        ],
    ],

];
