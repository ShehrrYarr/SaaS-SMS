<?php

/*
 * My salary slips (every staff portal): the signed-in staff member's paid salaries.
 */

use App\Support\Portal;
use App\Tables\PayslipsTable;

use function Livewire\Volt\{layout, mount, title};

layout('layouts.app');
title(__('My salary slips'));

mount(fn () => $this->authorize('view-own-payslips'));

?>

@php($portal = Portal::current() ?? 'teacher')

<div>
    <x-page-header :title="__('My salary slips')" :breadcrumbs="[Portal::label($portal) => route(Portal::dashboardRoute($portal)), __('My salary slips') => null]" />

    <livewire:components.data-table :table="PayslipsTable::class" :params="['scope' => 'self']" />
</div>
