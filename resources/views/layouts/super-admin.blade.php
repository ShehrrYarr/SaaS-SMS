{{-- Super Admin console layout (/super-admin): platform brand, platform menu, no school. --}}
@use('App\Support\Navigation\Menu')

<!DOCTYPE html>
<html @include('layouts.partials.html-attributes') data-main-layout="vertical" data-nav-position="sticky">
<head>
    @include('layouts.partials.head')
</head>
<body>
    @include('layouts.partials.header', [
        'portals' => [],
        'portal' => null,
        'academicYear' => null,
    ])

    @include('layouts.partials.sidebar', [
        'menu' => Menu::build('super-admin', auth()->user()),
        'brandName' => config('app.name'),
        'brandLogo' => null,
        'homeUrl' => route('central.dashboard'),
    ])

    <main class="app-wrapper">
        <div class="app-container">
            {{ $slot }}
        </div>
    </main>

    @include('layouts.partials.footer', ['owner' => config('app.name')])
</body>
</html>
