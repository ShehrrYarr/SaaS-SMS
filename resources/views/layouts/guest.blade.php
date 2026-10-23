{{-- Sign-in and other signed-out pages (Herozi auth layout): highlights on the left, the page on the right. --}}
<!DOCTYPE html>
<html @include('layouts.partials.html-attributes') data-auth-layout data-main-layout="close-sidebar" data-nav-position="hidden">
<head>
    @include('layouts.partials.head')
</head>
<body>
    <div class="account-pages">
        <img src="{{ asset('images/auth/auth_bg.jpeg') }}" alt="" class="auth-bg light">
        <img src="{{ asset('images/auth/auth_bg_dark.jpg') }}" alt="" class="auth-bg dark">

        <div class="container">
            <div class="justify-content-center row gy-0">

                <div class="col-lg-6 auth-banners">
                    <div class="bg-login card card-body m-0 h-100 border-0">
                        <img src="{{ asset('images/auth/bg-img-2.png') }}" class="img-fluid auth-banner" alt="">
                        <div class="auth-contain">
                            <div id="authHighlights" class="carousel slide" data-bs-ride="carousel">
                                <div class="carousel-indicators">
                                    @foreach ([0, 1, 2] as $slide)
                                        <button type="button" data-bs-target="#authHighlights" data-bs-slide-to="{{ $slide }}" @class(['active' => $slide === 0]) @if ($slide === 0) aria-current="true" @endif aria-label="{{ __('Slide :number', ['number' => $slide + 1]) }}"></button>
                                    @endforeach
                                </div>
                                <div class="carousel-inner">
                                    <div class="carousel-item active">
                                        <div class="text-center text-white my-4 p-4">
                                            <h3 class="text-white">{{ __('Your whole school, one place') }}</h3>
                                            <p class="mt-3">{{ __('Admissions, classes, timetables and records, shared by everyone who needs them.') }}</p>
                                        </div>
                                    </div>
                                    <div class="carousel-item">
                                        <div class="text-center text-white my-4 p-4">
                                            <h3 class="text-white">{{ __('Attendance, marks and fees') }}</h3>
                                            <p class="mt-3">{{ __('Teachers mark, parents see it, the office collects, all in real time.') }}</p>
                                        </div>
                                    </div>
                                    <div class="carousel-item">
                                        <div class="text-center text-white my-4 p-4">
                                            <h3 class="text-white">{{ __('Made for every role') }}</h3>
                                            <p class="mt-3">{{ __('Admins, teachers, students, parents, HR and accounts each get their own portal.') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="auth-box card card-body m-0 h-100 border-0 justify-content-center">
                        {{ $slot }}
                    </div>
                </div>

            </div>
        </div>
    </div>

    @if ($toasts = App\Support\Toast::pending())
        <div id="flash-toasts" hidden data-toasts="{{ json_encode($toasts) }}"></div>
    @endif
</body>
</html>
