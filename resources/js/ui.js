/**
 * Toasts and confirmation dialogs.
 *
 * From PHP:   $this->dispatch('notify', type: 'success', message: 'Saved')
 *             (the spec's array form, dispatch('notify', [...]), works too)
 * From Blade: SSMS.confirm({ title, text, danger: true }).then(ok => ok && $wire.delete(id))
 */

const STYLES = {
    success: { color: 'success', icon: 'ri-checkbox-circle-line' },
    error: { color: 'danger', icon: 'ri-error-warning-line' },
    danger: { color: 'danger', icon: 'ri-error-warning-line' },
    warning: { color: 'warning', icon: 'ri-alert-line' },
    info: { color: 'info', icon: 'ri-information-line' },
};

function stack() {
    let el = document.getElementById('toast-stack');

    if (!el) {
        el = document.createElement('div');
        el.id = 'toast-stack';
        el.className = 'toast-container position-fixed top-0 end-0 p-3';
        el.style.zIndex = '1090';
        document.body.append(el);
    }

    return el;
}

export function toast({ type = 'success', message = '' } = {}) {
    if (!message) {
        return;
    }

    const { color, icon } = STYLES[type] ?? STYLES.info;
    const el = document.createElement('div');

    el.className = `toast align-items-center border-0 text-bg-${color}`;
    el.setAttribute('role', type === 'error' || type === 'danger' ? 'alert' : 'status');
    el.setAttribute('aria-live', type === 'error' || type === 'danger' ? 'assertive' : 'polite');
    el.setAttribute('aria-atomic', 'true');
    el.innerHTML = `
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2"><i class="${icon} fs-5"></i><span></span></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
    // Messages are text, never markup.
    el.querySelector('.toast-body span').textContent = message;

    stack().append(el);
    el.addEventListener('hidden.bs.toast', () => el.remove());
    window.bootstrap.Toast.getOrCreateInstance(el, { delay: color === 'danger' ? 7000 : 4000 }).show();
}

export function confirmDialog({ title = 'Are you sure?', text = '', confirmText = 'Yes, continue', cancelText = 'Cancel', danger = false } = {}) {
    return window.Swal.fire({
        title,
        text,
        icon: danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: danger,
        buttonsStyling: false,
        customClass: {
            confirmButton: `btn btn-${danger ? 'danger' : 'primary'}`,
            cancelButton: 'btn btn-light me-2',
        },
    }).then((result) => result.isConfirmed);
}

// Livewire passes named arguments as an object and positional ones as an array.
const payload = (detail) => (Array.isArray(detail) ? detail[0] : detail) ?? {};

function showFlashedToasts() {
    const holder = document.getElementById('flash-toasts');

    if (!holder) {
        return;
    }

    JSON.parse(holder.dataset.toasts || '[]').forEach(toast);
    holder.remove();
}

export function bootUi() {
    window.addEventListener('notify', (event) => toast(payload(event.detail)));
    document.addEventListener('livewire:navigated', showFlashedToasts);

    window.SSMS = { toast, confirm: confirmDialog };
}
