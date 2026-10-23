<?php

use App\Tables\Central\SchoolsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.super-admin');
title(__('Schools'));

?>

<div>
    <x-page-header :title="__('Schools')" :breadcrumbs="[__('Super Admin') => route('central.dashboard'), __('Schools') => null]">
        <x-slot:actions>
            <a href="{{ route('central.schools.create') }}" wire:navigate class="btn btn-primary">
                <i class="ri-add-line me-1"></i>{{ __('New school') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <livewire:components.data-table :table="SchoolsTable::class" />
</div>
