<?php

namespace App\Livewire\Concerns;

use App\Support\Toast;

/**
 * UI helpers for Livewire/Volt components: toasts, the shared modal host and
 * data-table refreshes. Use in Volt with `uses([InteractsWithUi::class])`.
 *
 * The methods are protected so the browser can't call them as actions.
 */
trait InteractsWithUi
{
    /**
     * Show a toast now. Types: success, error, warning, info.
     */
    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('notify', type: $type, message: $message);
    }

    /**
     * Show a toast on the next page, for use before a redirect.
     */
    protected function flashToast(string $message, string $type = 'success'): void
    {
        Toast::flash($message, $type);
    }

    /**
     * Open a form component ("forms.*") in the shared modal.
     *
     * @param  array<string, mixed>  $params  passed to the form's mount()
     * @param  'sm'|'md'|'lg'|'xl'  $size
     */
    protected function openModal(string $component, array $params = [], string $size = 'md'): void
    {
        $this->dispatch('open-modal', component: $component, params: $params, size: $size);
    }

    protected function closeModal(): void
    {
        $this->dispatch('close-modal');
    }

    /**
     * Ask every data table on the page to reload its rows.
     */
    protected function refreshTables(): void
    {
        $this->dispatch('refresh-tables');
    }
}
