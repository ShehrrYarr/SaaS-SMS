/**
 * Herozi layout behaviour, rebuilt for Livewire's SPA navigation.
 *
 * wire:navigate swaps <body> but keeps <html>, so layout state lives in <html>
 * attributes (data-bs-theme, data-main-layout) and survives navigation. Event
 * listeners are delegated from `document` and bound once; per-page widgets
 * (tooltips, sidebar scroll) are re-initialised after every navigation.
 */

const KEYS = { theme: 'ssms.theme', sidebar: 'ssms.sidebar' };
const root = document.documentElement;

const storage = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // Storage unavailable (private mode): the choice lasts for this page only.
        }
    },
};

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const isAuthLayout = () => root.hasAttribute('data-auth-layout');

/** Apply light / dark / auto (follow the OS) and mark the active menu option. */
export function applyTheme(mode = storage.get(KEYS.theme) || 'light') {
    const resolved = mode === 'auto' ? (prefersDark() ? 'dark' : 'light') : mode;

    root.setAttribute('data-bs-theme', resolved);
    // Herozi colours the sidebar separately; keep it in step with the theme.
    root.setAttribute('data-sidebar', resolved === 'dark' ? 'dark-sidebar' : 'light-sidebar');

    document.querySelectorAll('[data-theme-mode]').forEach((option) => {
        option.classList.toggle('active', option.dataset.themeMode === mode);
    });
}

/** Sidebar follows the screen size; on wide screens it follows the user's choice. */
export function applySidebar() {
    if (isAuthLayout()) {
        return;
    }

    const width = window.innerWidth;
    const mode = width < 769 ? 'close-sidebar' : width < 1250 ? 'small-icon' : storage.get(KEYS.sidebar) || 'vertical';

    setSidebarMode(mode);
}

function toggleSidebar() {
    const next = root.getAttribute('data-main-layout') === 'small-icon' ? 'vertical' : 'small-icon';

    storage.set(KEYS.sidebar, next);
    setSidebarMode(next);
}

function setSidebarMode(mode) {
    if (root.getAttribute('data-main-layout') === mode) {
        return;
    }

    root.setAttribute('data-main-layout', mode);

    // Submenus listen for this to close their flyouts when the sidebar collapses.
    window.dispatchEvent(new CustomEvent('sidebar-mode-changed', { detail: mode }));
}

function scrollActiveMenuIntoView() {
    const menu = document.querySelector('.app-sidebar .app-sidebar-menu');
    const active = menu?.querySelector('.side-menu__item.active');

    if (menu && active) {
        menu.scrollTop = active.offsetTop - menu.clientHeight / 2;
    }
}

function initPage() {
    applyTheme();

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => window.bootstrap.Tooltip.getOrCreateInstance(el));
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach((el) => window.bootstrap.Popover.getOrCreateInstance(el));

    scrollActiveMenuIntoView();
}

let resizeTimer;

export function boot() {
    document.addEventListener('click', (event) => {
        if (event.target.closest('.sidebar-toggle')) {
            toggleSidebar();
        }

        const themeOption = event.target.closest('[data-theme-mode]');

        if (themeOption) {
            storage.set(KEYS.theme, themeOption.dataset.themeMode);
            applyTheme(themeOption.dataset.themeMode);
        }
    });

    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(applySidebar, 150);
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if ((storage.get(KEYS.theme) || 'light') === 'auto') {
            applyTheme('auto');
        }
    });

    // Fires on the first load and after every wire:navigate.
    document.addEventListener('livewire:navigated', initPage);
}
