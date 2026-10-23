<div class="dropdown features-dropdown">
    <button type="button" class="btn icon-btn btn-text-primary rounded-circle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('Colour theme') }}">
        <i class="ri-contrast-2-line fs-20"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-end">
        <button type="button" class="dropdown-item" data-theme-mode="light">
            <span class="hstack gap-2"><i class="ri-sun-line"></i>{{ __('Light') }}</span>
        </button>
        <button type="button" class="dropdown-item" data-theme-mode="dark">
            <span class="hstack gap-2"><i class="ri-moon-clear-line"></i>{{ __('Dark') }}</span>
        </button>
        <button type="button" class="dropdown-item" data-theme-mode="auto">
            <span class="hstack gap-2"><i class="ri-computer-line"></i>{{ __('System') }}</span>
        </button>
    </div>
</div>
