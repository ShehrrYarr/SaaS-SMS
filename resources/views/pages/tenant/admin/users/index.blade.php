<?php

use App\Tables\UsersTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('User accounts'));

?>

<x-list-page :title="__('User accounts')" :section="__('Administration')" :table="UsersTable::class">
    <p class="text-muted mt-n3 mb-4">{{ __('Accounts are created with staff, student and parent records. Here you manage their roles, status and passwords.') }}</p>
</x-list-page>
