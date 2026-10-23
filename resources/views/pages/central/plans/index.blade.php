<?php

use App\Tables\Central\PlansTable;

use function Livewire\Volt\{layout, title};

layout('layouts.super-admin');
title(__('Plans'));

?>

<div>
    <x-page-header :title="__('Plans')" :breadcrumbs="[__('Super Admin') => route('central.dashboard'), __('Plans') => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', { component: 'forms.central.plan', size: 'lg' })">
                <i class="ri-add-line me-1"></i>{{ __('New plan') }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <livewire:components.data-table :table="PlansTable::class" />
</div>
