{{-- Shared end of <body> for the signed-in layouts: the modal host and any toasts flashed before a redirect. --}}
<livewire:components.modal-host />

@if ($toasts = App\Support\Toast::pending())
    <div id="flash-toasts" hidden data-toasts="{{ json_encode($toasts) }}"></div>
@endif
