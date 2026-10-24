<?php

/*
|--------------------------------------------------------------------------
| Sidebar Navigation
|--------------------------------------------------------------------------
|
| One menu per portal, plus the Super Admin console. App\Support\Navigation\Menu
| filters each menu for the signed-in user:
|
|  - 'can'   hides an item the user lacks the permission for;
|  - 'route' hides an item whose route doesn't exist yet, so screens appear in
|            the menu automatically as later phases add their routes;
|  - headings and parents with nothing visible under them are dropped.
|
| An item is active for its route, or for every route under it when the route
| ends in ".index" (admin.students.index is active on admin.students.edit).
|
*/

return [

    'super-admin' => [
        ['heading' => 'Platform'],
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'central.dashboard'],
        ['label' => 'Schools', 'icon' => 'ri-building-4-line', 'route' => 'central.schools.index', 'can' => 'manage-schools'],
        ['heading' => 'Billing'],
        ['label' => 'Plans', 'icon' => 'ri-price-tag-3-line', 'route' => 'central.plans.index', 'can' => 'manage-subscriptions'],
        ['label' => 'Subscriptions', 'icon' => 'ri-bank-card-line', 'route' => 'central.subscriptions.index', 'can' => 'manage-subscriptions'],
        ['heading' => 'Insights'],
        ['label' => 'Analytics', 'icon' => 'ri-line-chart-line', 'route' => 'central.analytics', 'can' => 'view-analytics'],
    ],

    'admin' => [
        ['heading' => 'Main'],
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'admin.dashboard'],

        ['heading' => 'Academics'],
        ['label' => 'Academic Setup', 'icon' => 'ri-calendar-2-line', 'children' => [
            ['label' => 'Academic Years', 'route' => 'admin.academic-years.index', 'can' => 'academic-years.view'],
            ['label' => 'Terms', 'route' => 'admin.terms.index', 'can' => 'terms.view'],
            ['label' => 'Campuses', 'route' => 'admin.campuses.index', 'can' => 'campuses.view'],
            ['label' => 'Departments', 'route' => 'admin.departments.index', 'can' => 'departments.view'],
            ['label' => 'Classes & Sections', 'route' => 'admin.classes.index', 'can' => 'classes.view'],
            ['label' => 'Classrooms', 'route' => 'admin.classrooms.index', 'can' => 'classrooms.view'],
            ['label' => 'Subjects', 'route' => 'admin.subjects.index', 'can' => 'subjects.view'],
            ['label' => 'Subject Allocation', 'route' => 'admin.subjects.allocation', 'can' => 'subjects.allocate'],
        ]],
        ['label' => 'Timetable', 'icon' => 'ri-time-line', 'route' => 'admin.timetable.index', 'can' => 'timetable.view'],
        ['label' => 'Examinations', 'icon' => 'ri-file-list-3-line', 'children' => [
            ['label' => 'Examinations', 'route' => 'admin.examinations.index', 'can' => 'examinations.view'],
            ['label' => 'Grade Scales', 'route' => 'admin.grade-scales.index', 'can' => 'grade-scales.view'],
        ]],

        ['heading' => 'People'],
        ['label' => 'Students', 'icon' => 'ri-graduation-cap-line', 'children' => [
            ['label' => 'All Students', 'route' => 'admin.students.index', 'can' => 'students.view'],
            ['label' => 'Bulk Import', 'route' => 'admin.students.import', 'can' => 'students.import'],
            ['label' => 'Promotion', 'route' => 'admin.students.promote', 'can' => 'students.promote'],
        ]],
        ['label' => 'Parents', 'icon' => 'ri-parent-line', 'route' => 'admin.parents.index', 'can' => 'parents.view'],
        ['label' => 'Staff', 'icon' => 'ri-user-star-line', 'children' => [
            ['label' => 'All Staff', 'route' => 'admin.staff.index', 'can' => 'staff.view'],
            ['label' => 'Bulk Import', 'route' => 'admin.staff.import', 'can' => 'staff.import'],
        ]],
        ['label' => 'Attendance', 'icon' => 'ri-calendar-check-line', 'children' => [
            ['label' => 'Daily Registers', 'route' => 'admin.attendance.index', 'can' => 'attendance.manage'],
            ['label' => 'Monthly Report', 'route' => 'admin.attendance.report', 'can' => 'attendance.view'],
        ]],

        ['heading' => 'Communication'],
        ['label' => 'Notices', 'icon' => 'ri-megaphone-line', 'route' => 'admin.notices.index', 'can' => 'notices.view'],

        ['heading' => 'Operations'],
        ['label' => 'Library', 'icon' => 'ri-book-2-line', 'route' => 'admin.library.index', 'can' => 'library.view'],
        ['label' => 'Transport', 'icon' => 'ri-bus-2-line', 'route' => 'admin.transport.index', 'can' => 'transport.view'],
        ['label' => 'Hostel', 'icon' => 'ri-hotel-bed-line', 'route' => 'admin.hostel.index', 'can' => 'hostel.view'],

        ['heading' => 'Administration'],
        ['label' => 'Roles & Permissions', 'icon' => 'ri-key-2-line', 'route' => 'admin.roles.index', 'can' => 'roles.view'],
        ['label' => 'User Accounts', 'icon' => 'ri-account-circle-line', 'route' => 'admin.users.index', 'can' => 'users.view'],
        ['label' => 'Reports', 'icon' => 'ri-bar-chart-box-line', 'route' => 'admin.reports.index', 'can' => 'reports.view'],
        ['label' => 'Activity Log', 'icon' => 'ri-history-line', 'route' => 'admin.activity-log.index', 'can' => 'activity-log.view'],
        ['label' => 'School Settings', 'icon' => 'ri-settings-3-line', 'route' => 'admin.settings.index', 'can' => 'settings.view'],
        ['heading' => 'Me'],
        ['label' => 'My Leave', 'icon' => 'ri-calendar-event-line', 'route' => 'admin.my-leave', 'can' => 'apply-own-leave'],
        ['label' => 'My Salary Slips', 'icon' => 'ri-file-paper-2-line', 'route' => 'admin.my-payslips', 'can' => 'view-own-payslips'],
        ['label' => 'My Library Books', 'icon' => 'ri-book-2-line', 'route' => 'admin.my-library', 'can' => 'has-library-loans'],
    ],

    'teacher' => [
        ['heading' => 'Main'],
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'teacher.dashboard'],
        ['heading' => 'Classroom'],
        ['label' => 'My Classes', 'icon' => 'ri-group-line', 'route' => 'teacher.classes.index'],
        ['label' => 'Attendance', 'icon' => 'ri-calendar-check-line', 'route' => 'teacher.attendance.index', 'can' => 'attendance.mark'],
        ['label' => 'Attendance Report', 'icon' => 'ri-table-line', 'route' => 'teacher.attendance.report', 'can' => 'attendance.view'],
        ['label' => 'Gradebook', 'icon' => 'ri-file-edit-line', 'route' => 'teacher.gradebook.index', 'can' => 'marks.edit'],
        ['label' => 'Homework', 'icon' => 'ri-book-open-line', 'route' => 'teacher.homework.index', 'can' => 'homework.view'],
        ['label' => 'Student Leave', 'icon' => 'ri-calendar-close-line', 'route' => 'teacher.student-leave.index', 'can' => 'student-leave.view'],
        ['label' => 'Timetable', 'icon' => 'ri-time-line', 'route' => 'teacher.timetable', 'can' => 'timetable.view'],
        ['label' => 'Notice Board', 'icon' => 'ri-megaphone-line', 'route' => 'tenant.notices.index'],
        ['heading' => 'Me'],
        ['label' => 'My Leave', 'icon' => 'ri-calendar-event-line', 'route' => 'teacher.my-leave', 'can' => 'apply-own-leave'],
        ['label' => 'My Salary Slips', 'icon' => 'ri-file-paper-2-line', 'route' => 'teacher.my-payslips', 'can' => 'view-own-payslips'],
        ['label' => 'My Library Books', 'icon' => 'ri-book-2-line', 'route' => 'teacher.my-library', 'can' => 'has-library-loans'],
    ],

    'hr' => [
        ['heading' => 'Main'],
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'hr.dashboard'],
        ['heading' => 'People'],
        ['label' => 'Staff Directory', 'icon' => 'ri-contacts-book-line', 'route' => 'hr.staff.index', 'can' => 'staff.view'],
        ['label' => 'Staff Attendance', 'icon' => 'ri-calendar-check-line', 'route' => 'hr.attendance.index', 'can' => 'staff-attendance.view'],
        ['label' => 'Leave', 'icon' => 'ri-calendar-close-line', 'children' => [
            ['label' => 'Leave Requests', 'route' => 'hr.leave.index', 'can' => 'leave.view'],
            ['label' => 'Leave Balances', 'route' => 'hr.leave.balances', 'can' => 'leave.view'],
            ['label' => 'Leave Types', 'route' => 'hr.leave-types.index', 'can' => 'leave.manage'],
        ]],
        ['heading' => 'Payroll'],
        ['label' => 'Payroll', 'icon' => 'ri-money-dollar-box-line', 'route' => 'hr.payroll.index', 'can' => 'payroll.view'],
        ['label' => 'Salary Slips', 'icon' => 'ri-file-paper-2-line', 'route' => 'hr.payslips.index', 'can' => 'payroll.view'],
        ['heading' => 'Communication'],
        ['label' => 'Notice Board', 'icon' => 'ri-megaphone-line', 'route' => 'tenant.notices.index'],
        ['heading' => 'Me'],
        ['label' => 'My Leave', 'icon' => 'ri-calendar-event-line', 'route' => 'hr.my-leave', 'can' => 'apply-own-leave'],
        ['label' => 'My Salary Slips', 'icon' => 'ri-file-paper-2-line', 'route' => 'hr.my-payslips', 'can' => 'view-own-payslips'],
        ['label' => 'My Library Books', 'icon' => 'ri-book-2-line', 'route' => 'hr.my-library', 'can' => 'has-library-loans'],
    ],

    'accounting' => [
        ['heading' => 'Main'],
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'accounting.dashboard'],
        ['heading' => 'Fees'],
        ['label' => 'Fee Collection', 'icon' => 'ri-hand-coin-line', 'route' => 'accounting.collect', 'can' => 'fees.collect'],
        ['label' => 'Invoices', 'icon' => 'ri-bill-line', 'children' => [
            ['label' => 'All Invoices', 'route' => 'accounting.invoices.index', 'can' => 'invoices.view'],
            ['label' => 'Bulk Generation', 'route' => 'accounting.invoice-batches.index', 'can' => 'invoices.generate'],
        ]],
        ['label' => 'Payments', 'icon' => 'ri-secure-payment-line', 'route' => 'accounting.payments.index', 'can' => 'fees.view'],
        ['label' => 'Fee Setup', 'icon' => 'ri-settings-4-line', 'children' => [
            ['label' => 'Fee Structures', 'route' => 'accounting.fee-structures.index', 'can' => 'fee-structures.view'],
            ['label' => 'Discounts & Scholarships', 'route' => 'accounting.discounts.index', 'can' => 'fee-discounts.view'],
        ]],
        ['heading' => 'Ledger'],
        ['label' => 'Expenses', 'icon' => 'ri-shopping-bag-3-line', 'route' => 'accounting.expenses.index', 'can' => 'expenses.view'],
        ['label' => 'Income', 'icon' => 'ri-funds-line', 'route' => 'accounting.income.index', 'can' => 'income.view'],
        ['label' => 'Categories', 'icon' => 'ri-price-tag-3-line', 'route' => 'accounting.categories.index', 'can' => 'expenses.view'],
        ['label' => 'Reports', 'icon' => 'ri-bar-chart-box-line', 'route' => 'accounting.reports.index', 'can' => 'finance-reports.view'],
        ['heading' => 'Communication'],
        ['label' => 'Notice Board', 'icon' => 'ri-megaphone-line', 'route' => 'tenant.notices.index'],
        ['heading' => 'Me'],
        ['label' => 'My Leave', 'icon' => 'ri-calendar-event-line', 'route' => 'accounting.my-leave', 'can' => 'apply-own-leave'],
        ['label' => 'My Salary Slips', 'icon' => 'ri-file-paper-2-line', 'route' => 'accounting.my-payslips', 'can' => 'view-own-payslips'],
        ['label' => 'My Library Books', 'icon' => 'ri-book-2-line', 'route' => 'accounting.my-library', 'can' => 'has-library-loans'],
    ],

    'student' => [
        ['heading' => 'Main'],
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'student.dashboard'],
        ['heading' => 'My School'],
        ['label' => 'Timetable', 'icon' => 'ri-time-line', 'route' => 'student.timetable'],
        ['label' => 'Attendance', 'icon' => 'ri-calendar-check-line', 'route' => 'student.attendance'],
        ['label' => 'Homework', 'icon' => 'ri-book-open-line', 'route' => 'student.homework.index', 'can' => 'self-service-homework'],
        ['label' => 'Results', 'icon' => 'ri-award-line', 'route' => 'student.results', 'can' => 'self-service-results'],
        ['label' => 'Fees', 'icon' => 'ri-bill-line', 'route' => 'student.fees', 'can' => 'self-service-fees'],
        ['label' => 'Library', 'icon' => 'ri-book-2-line', 'route' => 'student.library', 'can' => 'self-service-library'],
        ['label' => 'Transport & Hostel', 'icon' => 'ri-bus-2-line', 'route' => 'student.services', 'can' => 'self-service-services'],
        ['label' => 'Notice Board', 'icon' => 'ri-megaphone-line', 'route' => 'tenant.notices.index'],
    ],

    // Every page shows the child picked in the header's child switcher.
    'parent' => [
        ['heading' => 'Main'],
        ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'parent.dashboard'],
        ['heading' => 'My Children'],
        ['label' => 'Timetable', 'icon' => 'ri-time-line', 'route' => 'parent.timetable'],
        ['label' => 'Attendance', 'icon' => 'ri-calendar-check-line', 'route' => 'parent.attendance'],
        ['label' => 'Homework', 'icon' => 'ri-book-open-line', 'route' => 'parent.homework.index', 'can' => 'self-service-homework'],
        ['label' => 'Results', 'icon' => 'ri-award-line', 'route' => 'parent.results', 'can' => 'self-service-results'],
        ['label' => 'Fees', 'icon' => 'ri-bill-line', 'route' => 'parent.fees', 'can' => 'self-service-fees'],
        ['label' => 'Library', 'icon' => 'ri-book-2-line', 'route' => 'parent.library', 'can' => 'self-service-library'],
        ['label' => 'Transport & Hostel', 'icon' => 'ri-bus-2-line', 'route' => 'parent.services', 'can' => 'self-service-services'],
        ['label' => 'Leave Requests', 'icon' => 'ri-calendar-close-line', 'route' => 'parent.leave.index'],
        ['label' => 'Notice Board', 'icon' => 'ri-megaphone-line', 'route' => 'tenant.notices.index'],
    ],

];
