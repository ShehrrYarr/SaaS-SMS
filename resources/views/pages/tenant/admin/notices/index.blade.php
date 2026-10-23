<?php

use App\Tables\NoticesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Notices'));

?>

<x-list-page :title="__('Notices')" :section="__('Communication')" :table="NoticesTable::class"
             create="forms.notice" :create-label="__('New notice')" create-permission="notices.create" create-size="lg">
    <x-slot:actions>
        <a href="{{ route('tenant.notices.index') }}" wire:navigate class="btn btn-light"><i class="ri-layout-masonry-line me-1"></i>{{ __('View board') }}</a>
    </x-slot:actions>
</x-list-page>
