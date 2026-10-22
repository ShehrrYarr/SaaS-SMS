<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Routing
    |--------------------------------------------------------------------------
    |
    | Schools are addressed by a path prefix: /{school}/admin, /{school}/teacher.
    | The Super Admin console lives under the central prefix and never carries
    | a school.
    |
    */

    'route_parameter' => 'school',

    'central_prefix' => 'super-admin',

    /*
    |--------------------------------------------------------------------------
    | Platform Super Admin
    |--------------------------------------------------------------------------
    |
    | Seeded by SuperAdminSeeder. Set these in .env before the first deploy.
    |
    */

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Platform Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved Slugs
    |--------------------------------------------------------------------------
    |
    | A school slug becomes the first URL segment, so it must never shadow a
    | platform route, a public asset folder or a well-known path.
    |
    */

    'reserved_slugs' => [
        'super-admin', 'admin', 'administrator', 'root', 'system', 'platform',
        'login', 'logout', 'register', 'signup', 'signin', 'password',
        'forgot-password', 'reset-password', 'verify', 'auth', 'account',
        'livewire', 'livewire-update', 'api', 'up', 'health', 'status',
        'storage', 'build', 'assets', 'fonts', 'images', 'vendor', 'public',
        'favicon.ico', 'robots.txt', 'sitemap.xml',
        'app', 'www', 'mail', 'ftp', 'dashboard', 'settings', 'billing',
        'docs', 'help', 'support', 'about', 'contact', 'pricing', 'blog',
        'terms', 'privacy', 'demo', 'test', 'staging', 'herozi',
        'teacher', 'student', 'parent', 'hr', 'accounting',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default School Settings
    |--------------------------------------------------------------------------
    |
    | Merged under each school's own `settings` JSON, so a school only stores
    | the values it has changed. Read them with $school->setting('key').
    |
    */

    'default_settings' => [
        'currency_code' => 'USD',
        'currency_symbol' => '$',
        'timezone' => 'UTC',
        'date_format' => 'd M Y',
        'time_format' => 'h:i A',
        'week_starts_on' => 1, // ISO day: 1 = Monday

        'numbering' => [
            'admission_prefix' => 'ADM-',
            'employee_prefix' => 'EMP-',
            'invoice_prefix' => 'INV-',
            'receipt_prefix' => 'RCT-',
        ],

        'fees' => [
            'discounts_enabled' => true,
            'sibling_discount_enabled' => false,
            'bulk_generation_enabled' => true,
            'late_fee' => [
                'enabled' => false,
                'type' => 'fixed', // fixed | daily | percentage
                'amount' => 0,
                'grace_days' => 0,
                'max_amount' => null,
            ],
        ],
    ],

];
