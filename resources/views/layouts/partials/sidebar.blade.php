{{-- Expects: $menu (Menu::build output), $brandName, $brandLogo, $homeUrl --}}
<aside class="app-sidebar">
    <div class="app-sidebar-logo px-6 justify-content-center align-items-center">
        <a href="{{ $homeUrl }}" wire:navigate class="text-decoration-none" aria-label="{{ $brandName }}">
            <span class="app-sidebar-logo-default"><x-brand :name="$brandName" :logo="$brandLogo" /></span>
            <span class="app-sidebar-logo-minimize"><x-brand :name="$brandName" :logo="$brandLogo" compact /></span>
        </a>
    </div>
    <nav class="app-sidebar-menu nav nav-pills flex-column fs-6" aria-label="{{ __('Main navigation') }}">
        <ul class="main-menu" role="menu">
            @include('layouts.partials.menu-items', ['items' => $menu])
        </ul>
    </nav>
</aside>

{{-- Phones: the same menu in an offcanvas, opened from the header. --}}
<div class="offcanvas offcanvas-start small-screen-sidebar" data-bs-scroll="true" tabindex="-1" id="smallScreenSidebar" aria-labelledby="smallScreenSidebarLabel">
    <div class="offcanvas-header hstack border-bottom">
        <div class="app-sidebar-logo flex-grow-1" id="smallScreenSidebarLabel">
            <x-brand :name="$brandName" :logo="$brandLogo" />
        </div>
        <button type="button" class="btn-close bg-transparent" data-bs-dismiss="offcanvas" aria-label="{{ __('Close menu') }}">
            <i class="ri-close-line"></i>
        </button>
    </div>
    <div class="offcanvas-body p-0">
        <aside class="app-sidebar">
            <nav class="app-sidebar-menu nav nav-pills flex-column fs-6" aria-label="{{ __('Main navigation') }}">
                <ul class="main-menu" role="menu">
                    @include('layouts.partials.menu-items', ['items' => $menu])
                </ul>
            </nav>
        </aside>
    </div>
</div>
