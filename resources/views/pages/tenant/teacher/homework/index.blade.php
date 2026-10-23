<?php

/*
 * Teacher portal: homework I set, and what's waiting to be graded.
 */

use App\Services\Homework\Homeworks;
use App\Tables\HomeworkTable;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Homework'));

$canCreate = computed(fn () => auth()->user()->can('homework.create') && app(Homeworks::class)->allocationsFor(auth()->user())->isNotEmpty());

?>

<div>
    <x-page-header :title="__('Homework')" :breadcrumbs="[__('Teacher') => route('teacher.dashboard'), __('Homework') => null]">
        @if ($this->canCreate)
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', { component: 'forms.homework', size: 'lg' })">
                    <i class="ri-add-line me-1"></i>{{ __('Set homework') }}
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <livewire:components.data-table :table="HomeworkTable::class" />
</div>
