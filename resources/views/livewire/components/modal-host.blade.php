<?php

/*
 * The one Bootstrap modal every page shares (included by the app layouts).
 *
 * Open a form from anywhere:
 *     PHP:   $this->dispatch('open-modal', component: 'forms.academic-year', params: ['academicYear' => 3]);
 *     Blade: wire:click="$dispatch('open-modal', { component: 'forms.academic-year' })"
 * Close it from the form:  $this->dispatch('close-modal')
 *
 * Only "forms.*" components can be opened, and each form authorises itself
 * in mount(), so a crafted event can't render anything else.
 */

use function Livewire\Volt\{on, state};

state(['component' => null, 'params' => [], 'size' => 'md', 'opened' => 0]);

on([
    'open-modal' => function (string $component, array $params = [], string $size = 'md') {
        abort_unless(str_starts_with($component, 'forms.'), 403);

        $this->component = $component;
        $this->params = $params;
        $this->size = in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
        $this->opened++;

        // Sent after the form has rendered into the modal.
        $this->dispatch('modal-show');
    },
    'close-modal' => fn () => $this->dispatch('modal-hide'),
]);

$clear = function () {
    $this->reset('component', 'params', 'size');
};

?>

<div>
    <div class="modal fade" id="app-modal" tabindex="-1" aria-hidden="true" wire:ignore.self
         x-data
         x-init="$el.addEventListener('hidden.bs.modal', () => $wire.clear())"
         x-on:modal-show.window="bootstrap.Modal.getOrCreateInstance($el).show()"
         x-on:modal-hide.window="bootstrap.Modal.getOrCreateInstance($el).hide()">
        <div @class(['modal-dialog modal-dialog-centered modal-dialog-scrollable', 'modal-'.$size => $size !== 'md'])>
            <div class="modal-content">
                @if ($component)
                    @livewire($component, $params, key('modal-'.$opened))
                @endif
            </div>
        </div>
    </div>
</div>
