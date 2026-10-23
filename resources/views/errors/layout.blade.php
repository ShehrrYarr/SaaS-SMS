{{--
    Error page shell. Deliberately session-free (no CSRF token, no Livewire):
    it must render even when the request failed before the session started.
--}}
@php
    $homeUrl = url('/');

    try {
        if (tenant() && auth()->check()) {
            $homeUrl = route('tenant.home');
        } elseif (tenant()) {
            $homeUrl = route('tenant.login');
        } elseif (auth()->user()?->isSuperAdmin()) {
            $homeUrl = route('central.dashboard');
        }
    } catch (Throwable) {
        // Fall back to the platform home.
    }
@endphp
<!DOCTYPE html>
<html @include('layouts.partials.html-attributes') data-auth-layout data-main-layout="close-sidebar" data-nav-position="hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <script>
        try {
            var theme = localStorage.getItem('ssms.theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', theme === 'auto'
                ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme);
        } catch (e) {}
    </script>
    @vite(['resources/scss/bootstrap.scss', 'resources/scss/icons.scss', 'resources/scss/app.scss', 'resources/scss/custom.scss'])
</head>
<body>
    <div class="account-pages">
        <img src="{{ asset('images/auth/auth_bg.jpeg') }}" alt="" class="auth-bg light">
        <img src="{{ asset('images/auth/auth_bg_dark.jpg') }}" alt="" class="auth-bg dark">
        <div class="container">
            <div class="justify-content-center row gy-0">
                <div class="col-lg-8">
                    <div class="auth-box card card-body m-0 h-100 border-0 justify-content-center text-center">
                        <img class="max-h-320px mx-auto img-fluid" src="{{ asset('images/errors/'.trim($__env->yieldContent('image', 'coming-soon.svg'))) }}" alt="">
                        <div class="mt-10">
                            <p class="text-primary fw-semibold mb-1">{{ __('Error :code', ['code' => trim($__env->yieldContent('code'))]) }}</p>
                            <h4 class="fw-semibold mb-2">@yield('title')</h4>
                            <p class="text-muted mb-8 mx-auto" style="max-width: 32rem">@yield('message')</p>
                            <div class="d-flex flex-wrap justify-content-center gap-2">
                                <button type="button" class="btn btn-light rounded-pill" onclick="history.back()">
                                    <i class="ri-arrow-left-line me-1"></i>{{ __('Go back') }}
                                </button>
                                <a href="{{ $homeUrl }}" class="btn btn-primary rounded-pill">
                                    <i class="ri-home-2-line me-1"></i>{{ __('Home') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
