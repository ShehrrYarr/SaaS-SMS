<?php

use App\Tables\AcademicYearsTable;

use function Livewire\Volt\{layout, mount, title};

layout('layouts.app');
title(__('Academic Years'));

mount(fn () => $this->authorize('academic-years.view'));

?>

<div>
    <x-page-header :title="__('Academic Years')" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Academic Setup') => null, __('Academic Years') => null]">
        @can('academic-years.create')
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', { component: 'forms.academic-year' })">
                    <i class="ri-add-line me-1"></i>{{ __('New academic year') }}
                </button>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <livewire:components.data-table :table="AcademicYearsTable::class" />
</div>
