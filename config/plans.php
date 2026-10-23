<?php

/*
|--------------------------------------------------------------------------
| SaaS Plans & Subscriptions
|--------------------------------------------------------------------------
|
| Plans are managed by the Super Admin and paid for offline: payments are
| recorded by hand in the console and extend the school's paid-until date.
|
*/

return [

    // Every plan price and subscription payment is in this currency.
    'currency' => env('BILLING_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Optional Features
    |--------------------------------------------------------------------------
    |
    | What a plan can switch on or off. Each feature unlocks permission
    | modules from config/access.php (and, optionally, whole portals: a portal
    | listed under several features opens when any of them is included).
    | Modules not listed here are core and come with every plan.
    |
    | A plan with no feature list (null) includes everything, as does a school
    | with no plan.
    |
    */

    'features' => [
        'examinations' => [
            'label' => 'Examinations & report cards',
            'modules' => ['examinations', 'grade-scales', 'marks'],
        ],
        'homework' => [
            'label' => 'Homework',
            'modules' => ['homework'],
        ],
        'finance' => [
            'label' => 'Fees & accounting',
            'modules' => ['fee-structures', 'fee-discounts', 'invoices', 'fees', 'expenses', 'income', 'finance-reports'],
            'portals' => ['accounting'],
        ],
        'hr' => [
            'label' => 'HR, staff attendance & leave',
            'modules' => ['staff-attendance', 'leave'],
            'portals' => ['hr'],
        ],
        'payroll' => [
            'label' => 'Payroll & salary slips',
            'modules' => ['payroll'],
            'portals' => ['hr'],
        ],
        'library' => [
            'label' => 'Library',
            'modules' => ['library'],
        ],
        'transport' => [
            'label' => 'Transport',
            'modules' => ['transport'],
        ],
        'hostel' => [
            'label' => 'Hostel',
            'modules' => ['hostel'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Enforcement
    |--------------------------------------------------------------------------
    |
    | When a school's trial or paid period ends it keeps working for
    | `grace_days`, then `schools:enforce-subscriptions` (scheduled daily)
    | suspends it. School admins see a warning `warning_days` before the end.
    |
    */

    'subscriptions' => [
        'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
        'warning_days' => (int) env('SUBSCRIPTION_WARNING_DAYS', 14),
        'auto_suspend' => (bool) env('SUBSCRIPTION_AUTO_SUSPEND', true),
    ],

];
