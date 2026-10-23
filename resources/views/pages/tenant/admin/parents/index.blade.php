<?php

use App\Tables\ParentsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Parents'));

?>

<x-list-page :title="__('Parents & guardians')" :section="__('People')" :table="ParentsTable::class"
             create="forms.parent" :create-label="__('New parent')" create-permission="parents.create" create-size="lg">
    <p class="text-muted mt-n3 mb-4">{{ __('Each parent has one account for all their children, and signs in to the Parent portal.') }}</p>
</x-list-page>
