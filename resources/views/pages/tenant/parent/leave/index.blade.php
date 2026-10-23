<?php

use App\Tables\StudentLeaveTable;

use function Livewire\Volt\{layout, mount, title};

layout('layouts.app');
title(__('Leave requests'));

mount(function () {
    abort_unless(auth()->user()->parentProfile()->exists(), 403);
});

?>

<div>
    <x-page-header :title="__('Leave requests')" :breadcrumbs="[__('Parent') => route('parent.dashboard'), __('Leave requests') => null]">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', { component: 'forms.student-leave', params: { as: 'parent' } })">
                <i class="ri-add-line me-1"></i>{{ __('Apply for leave') }}
            </button>
        </x-slot:actions>
    </x-page-header>

    <p class="text-muted mt-n3 mb-4">{{ __('Tell the school when your child will be away. The class teacher approves the request.') }}</p>

    <livewire:components.data-table :table="StudentLeaveTable::class" :params="['scope' => 'parent']" />
</div>
