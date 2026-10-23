{{--
    School portal layout (/{school}/admin, /teacher, /hr, ...). The sidebar is
    built for the current portal from config/navigation.php, filtered by the
    user's permissions in this school.
--}}
@use('App\Support\Navigation\Menu')
@use('App\Support\Portal')

@php
    $school = tenant();
    $user = auth()->user();
    $portal = Portal::current();
@endphp

<!DOCTYPE html>
<html @include('layouts.partials.html-attributes') data-main-layout="vertical" data-nav-position="sticky">
<head>
    @include('layouts.partials.head')
</head>
<body>
    @include('layouts.partials.header', [
        'portals' => $user->accessiblePortals(),
        'portal' => $portal,
        'academicYear' => $school->currentAcademicYear,
    ])

    @include('layouts.partials.sidebar', [
        'menu' => $portal ? Menu::build($portal, $user) : [],
        'brandName' => $school->name,
        'brandLogo' => $school->logoUrl(),
        'homeUrl' => $portal ? route(Portal::dashboardRoute($portal)) : route('tenant.home'),
    ])

    <main class="app-wrapper">
        <div class="app-container">
            <x-subscription-notice :school="$school" />

            {{ $slot }}
        </div>
    </main>

    @include('layouts.partials.footer', ['owner' => $school->name])

    @include('layouts.partials.page-end')
</body>
</html>
