<?php

/*
|--------------------------------------------------------------------------
| Access Catalog
|--------------------------------------------------------------------------
|
| The single source of truth for every permission in the system. The
| PermissionCatalogSeeder syncs it into Spatie's `permissions` table and the
| role matrix (Phase 5) renders from it.
|
| Naming: school permissions are "<module>.<action>" (attendance.mark,
| fees.collect). Portal entry is "portal.<portal>". Platform permissions
| belong to the Super Admin console and are never assigned inside a school.
|
*/

return [

    'platform' => [
        'manage-schools' => 'Manage schools',
        'manage-subscriptions' => 'Manage plans & subscriptions',
        'view-analytics' => 'View platform analytics',
    ],

    // Order is the order of the portal switcher, and the first portal a user
    // can enter is where they land after signing in. Self-service portals show
    // the signed-in student's or parent's own records, so "*" never grants them.
    'portals' => [
        'admin' => ['label' => 'School Admin', 'icon' => 'ri-shield-user-line'],
        'teacher' => ['label' => 'Teacher', 'icon' => 'ri-presentation-line'],
        'hr' => ['label' => 'HR', 'icon' => 'ri-team-line'],
        'accounting' => ['label' => 'Accounting', 'icon' => 'ri-wallet-3-line'],
        'student' => ['label' => 'Student', 'icon' => 'ri-graduation-cap-line', 'self_service' => true],
        'parent' => ['label' => 'Parent', 'icon' => 'ri-parent-line', 'self_service' => true],
    ],

    'action_labels' => [
        'view' => 'View',
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'manage' => 'Manage',
        'import' => 'Import',
        'promote' => 'Promote',
        'allocate' => 'Allocate',
        'mark' => 'Mark',
        'publish' => 'Publish',
        'grade' => 'Grade',
        'apply' => 'Apply',
        'approve' => 'Approve',
        'generate' => 'Generate',
        'pay' => 'Pay',
        'collect' => 'Collect',
        'void' => 'Void',
        'cancel' => 'Cancel',
        'issue' => 'Issue',
        'reset-password' => 'Reset password',
    ],

    'modules' => [
        // School Admin portal
        'settings' => ['label' => 'School Settings', 'portal' => 'admin', 'actions' => ['view', 'edit']],
        'roles' => ['label' => 'Roles & Permissions', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'users' => ['label' => 'User Accounts', 'portal' => 'admin', 'actions' => ['view', 'edit', 'reset-password']],
        'academic-years' => ['label' => 'Academic Years', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'terms' => ['label' => 'Terms', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'campuses' => ['label' => 'Campuses', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'departments' => ['label' => 'Departments', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'classes' => ['label' => 'Classes', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'sections' => ['label' => 'Sections', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'classrooms' => ['label' => 'Classrooms', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'subjects' => ['label' => 'Subjects', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete', 'allocate']],
        'timetable' => ['label' => 'Timetable', 'portal' => 'admin', 'actions' => ['view', 'manage']],
        'students' => ['label' => 'Students', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete', 'import', 'promote']],
        'parents' => ['label' => 'Parents', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'staff' => ['label' => 'Staff', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete', 'import']],
        'examinations' => ['label' => 'Examinations', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete', 'publish']],
        'grade-scales' => ['label' => 'Grade Scales', 'portal' => 'admin', 'actions' => ['view', 'manage']],
        'notices' => ['label' => 'Notices', 'portal' => 'admin', 'actions' => ['view', 'create', 'edit', 'delete']],
        'reports' => ['label' => 'Reports', 'portal' => 'admin', 'actions' => ['view']],
        'activity-log' => ['label' => 'Activity Log', 'portal' => 'admin', 'actions' => ['view']],
        'library' => ['label' => 'Library', 'portal' => 'admin', 'actions' => ['view', 'manage', 'issue']],
        'transport' => ['label' => 'Transport', 'portal' => 'admin', 'actions' => ['view', 'manage']],
        'hostel' => ['label' => 'Hostel', 'portal' => 'admin', 'actions' => ['view', 'manage']],

        // Teacher portal ("manage" = any class / past dates, not just own sections)
        'attendance' => ['label' => 'Student Attendance', 'portal' => 'teacher', 'actions' => ['view', 'mark', 'manage']],
        'marks' => ['label' => 'Marks & Gradebook', 'portal' => 'teacher', 'actions' => ['view', 'edit', 'manage']],
        'homework' => ['label' => 'Homework', 'portal' => 'teacher', 'actions' => ['view', 'create', 'edit', 'delete', 'grade', 'manage']],
        'student-leave' => ['label' => 'Student Leave', 'portal' => 'teacher', 'actions' => ['view', 'approve']],

        // HR portal
        'staff-attendance' => ['label' => 'Staff Attendance', 'portal' => 'hr', 'actions' => ['view', 'mark']],
        'leave' => ['label' => 'Staff Leave', 'portal' => 'hr', 'actions' => ['apply', 'view', 'approve', 'manage']],
        'payroll' => ['label' => 'Payroll', 'portal' => 'hr', 'actions' => ['view', 'generate', 'approve', 'pay']],

        // Accounting portal
        'fee-structures' => ['label' => 'Fee Structures', 'portal' => 'accounting', 'actions' => ['view', 'create', 'edit', 'delete']],
        'fee-discounts' => ['label' => 'Discounts & Scholarships', 'portal' => 'accounting', 'actions' => ['view', 'manage']],
        'invoices' => ['label' => 'Invoices', 'portal' => 'accounting', 'actions' => ['view', 'create', 'generate', 'edit', 'cancel']],
        'fees' => ['label' => 'Fee Collection', 'portal' => 'accounting', 'actions' => ['view', 'collect', 'void']],
        'expenses' => ['label' => 'Expenses', 'portal' => 'accounting', 'actions' => ['view', 'create', 'edit', 'delete']],
        'income' => ['label' => 'Income', 'portal' => 'accounting', 'actions' => ['view', 'create', 'edit', 'delete']],
        'finance-reports' => ['label' => 'Finance Reports', 'portal' => 'accounting', 'actions' => ['view']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default School Roles
    |--------------------------------------------------------------------------
    |
    | Created for every school at provisioning time and flagged as system
    | roles (permissions editable, role not deletable). "*" grants every
    | school permission. Entries ending in ".*" expand to the whole module.
    |
    */

    'default_roles' => [
        'School Admin' => [
            'description' => 'Full access to everything in the school.',
            'permissions' => ['*'],
        ],
        'Teacher' => [
            'description' => 'Class rosters, attendance, gradebook and homework for assigned classes.',
            'permissions' => [
                'portal.teacher', 'attendance.view', 'attendance.mark', 'marks.view', 'marks.edit',
                'homework.view', 'homework.create', 'homework.edit', 'homework.delete', 'homework.grade',
                'student-leave.*', 'students.view', 'timetable.view',
                'examinations.view', 'leave.apply',
            ],
        ],
        'HR' => [
            'description' => 'Staff records, staff attendance, leave approvals and payroll.',
            'permissions' => [
                'portal.hr', 'staff.view', 'staff.create', 'staff.edit', 'staff-attendance.*',
                'leave.*', 'payroll.*', 'departments.view',
            ],
        ],
        'Accountant' => [
            'description' => 'Fee structures, invoicing, fee collection, expenses and income.',
            'permissions' => [
                'portal.accounting', 'fee-structures.*', 'fee-discounts.*', 'invoices.*', 'fees.*',
                'expenses.*', 'income.*', 'finance-reports.view', 'students.view', 'parents.view',
                'leave.apply',
            ],
        ],
        'Librarian' => [
            'description' => 'Library catalogue and book issuing.',
            'permissions' => ['portal.admin', 'library.*', 'students.view', 'leave.apply'],
        ],
        'Student' => [
            'description' => 'Student self-service portal.',
            'permissions' => ['portal.student'],
        ],
        'Parent' => [
            'description' => 'Parent self-service portal.',
            'permissions' => ['portal.parent'],
        ],
    ],

];
