{{--
    Header, body and footer of a form shown in the shared modal host.
    Use as the root element of a "forms.*" component:

        <x-modal.form :title="__('New academic year')" submit="save">
            ...fields...
        </x-modal.form>
--}}
@props(['title', 'submit' => 'save', 'submitLabel' => __('Save'), 'subtitle' => null])

<form wire:submit="{{ $submit }}" novalidate {{ $attributes->class('modal-form') }}>
    <div class="modal-header">
        <div>
            <h5 class="modal-title">{{ $title }}</h5>
            @if ($subtitle)
                <p class="text-muted fs-13 mb-0">{{ $subtitle }}</p>
            @endif
        </div>
        <button type="button" class="btn btn-text-primary icon-btn-sm" data-bs-dismiss="modal" aria-label="{{ __('Close') }}">
            <i class="ri-close-large-line fw-semibold"></i>
        </button>
    </div>

    <div class="modal-body">
        {{ $slot }}
    </div>

    <div class="modal-footer">
        {{ $footer ?? '' }}
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="{{ $submit }}">
            <span wire:loading.remove wire:target="{{ $submit }}">{{ $submitLabel }}</span>
            <span wire:loading wire:target="{{ $submit }}"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('Saving…') }}</span>
        </button>
    </div>
</form>
