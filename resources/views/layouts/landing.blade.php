{{-- The public landing page: top navigation, the page and a footer. --}}
<!DOCTYPE html>
<html @include('layouts.partials.html-attributes') data-auth-layout data-main-layout="close-sidebar" data-nav-position="hidden">
<head>
    @include('layouts.partials.head')
    <meta name="description" content="{{ __(':app is cloud school management: admissions, attendance, timetable, exams, fees, HR, payroll, library, transport and hostel, with portals for teachers, students and parents.', ['app' => config('app.name')]) }}">
</head>
<body class="landing">
    <nav class="landing-nav navbar navbar-expand-lg sticky-top" aria-label="{{ __('Main') }}">
        <div class="container">
            <a class="navbar-brand" href="{{ route('home') }}"><x-brand :name="config('app.name')" /></a>
            <button class="navbar-toggler border-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#landingNav" aria-controls="landingNav" aria-expanded="false" aria-label="{{ __('Menu') }}">
                <i class="ri-menu-line fs-4"></i>
            </button>
            <div class="collapse navbar-collapse" id="landingNav">
                <ul class="navbar-nav ms-auto gap-lg-1">
                    <li class="nav-item"><a class="nav-link" href="#features">{{ __('Features') }}</a></li>
                    @if (App\Support\Demo::enabled())
                        <li class="nav-item"><a class="nav-link" href="#demo">{{ __('Live demo') }}</a></li>
                    @endif
                    <li class="nav-item"><a class="nav-link" href="#how-it-works">{{ __('How it works') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing">{{ __('Pricing') }}</a></li>
                </ul>
                <a href="#sign-in" class="btn btn-outline-primary rounded-2 ms-lg-3 mt-2 mt-lg-0">{{ __('School sign in') }}</a>
            </div>
        </div>
    </nav>

    <main>
        {{ $slot }}
    </main>

    <footer class="landing-footer">
        <div class="container">
            <div class="row gy-4">
                <div class="col-lg-5">
                    <x-brand :name="config('app.name')" />
                    <p class="text-muted mt-3 mb-0">{{ __('Cloud school management for schools, campuses and school groups.') }}</p>
                </div>
                <div class="col-6 col-lg-3 offset-lg-1">
                    <h6 class="text-uppercase fs-12 text-muted mb-3">{{ __('Product') }}</h6>
                    <ul class="list-unstyled d-grid gap-2 mb-0">
                        <li><a href="#features">{{ __('Features') }}</a></li>
                        <li><a href="#how-it-works">{{ __('How it works') }}</a></li>
                        <li><a href="#pricing">{{ __('Pricing') }}</a></li>
                    </ul>
                </div>
                <div class="col-6 col-lg-3">
                    <h6 class="text-uppercase fs-12 text-muted mb-3">{{ __('Get started') }}</h6>
                    <ul class="list-unstyled d-grid gap-2 mb-0">
                        @if (App\Support\Demo::enabled())
                            <li><a href="#demo">{{ __('Try the demo school') }}</a></li>
                        @endif
                        <li><a href="#sign-in">{{ __('School sign in') }}</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-top mt-5 pt-4 text-muted fs-13">
                &copy; {{ now()->year }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
            </div>
        </div>
    </footer>

    @if ($toasts = App\Support\Toast::pending())
        <div id="flash-toasts" hidden data-toasts="{{ json_encode($toasts) }}"></div>
    @endif
</body>
</html>
