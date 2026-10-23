{{-- Expects: $portals (portal keys the user can switch to), $portal (current), $academicYear (or null) --}}
@use('App\Support\Portal')

<header class="app-header">
    <div class="container-fluid">
        <div class="nav-header">

            <div class="header-left hstack gap-3">
                <button type="button" class="btn btn-light-light icon-btn sidebar-toggle d-none d-md-block" aria-label="{{ __('Collapse or expand the sidebar') }}">
                    <i class="ri-menu-2-fill"></i>
                </button>
                <button type="button" class="btn btn-light-light icon-btn d-md-none" data-bs-toggle="offcanvas" data-bs-target="#smallScreenSidebar" aria-controls="smallScreenSidebar" aria-label="{{ __('Open menu') }}">
                    <i class="ri-menu-2-fill"></i>
                </button>

                @if ($academicYear)
                    <span class="badge bg-primary-subtle text-primary d-none d-sm-inline-flex align-items-center gap-1 py-2 px-3" title="{{ __('Current academic year') }}">
                        <i class="ri-calendar-2-line"></i>{{ $academicYear->name }}
                    </span>
                @endif
            </div>

            <div class="header-right hstack gap-3">
                <div class="hstack gap-0 gap-sm-1">
                    @if ($portal === 'parent')
                        <livewire:layout.child-switcher />
                    @endif

                    @if (count($portals) > 1 && $portal)
                        <div class="dropdown features-dropdown portal-switcher">
                            <button type="button" class="btn btn-sm btn-subtle-primary hstack gap-2" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Switch portal') }}">
                                <i class="{{ Portal::icon($portal) }}"></i>
                                <span class="d-none d-md-inline">{{ Portal::label($portal) }}</span>
                                <i class="ri-arrow-down-s-line"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <span class="dropdown-header fs-12">{{ __('Switch portal') }}</span>
                                @foreach ($portals as $key)
                                    <a href="{{ route(Portal::dashboardRoute($key)) }}" wire:navigate @class(['dropdown-item hstack gap-2', 'active' => $key === $portal]) @if ($key === $portal) aria-current="page" @endif>
                                        <i class="{{ Portal::icon($key) }}"></i>{{ Portal::label($key) }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @include('layouts.partials.theme-toggle')

                    <livewire:layout.notifications />
                </div>

                <livewire:layout.user-menu />
            </div>

        </div>
    </div>
</header>
