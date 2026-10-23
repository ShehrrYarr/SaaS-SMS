<?php

use App\Tables\RolesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Roles & permissions'));

?>

<x-list-page :title="__('Roles & permissions')" :section="__('Administration')" :table="RolesTable::class"
             create="forms.role" :create-label="__('New role')" create-permission="roles.create">
    <p class="text-muted mt-n3 mb-4">{{ __('A role is a set of permissions. Give users roles on the User accounts page; a user can hold several.') }}</p>
</x-list-page>
