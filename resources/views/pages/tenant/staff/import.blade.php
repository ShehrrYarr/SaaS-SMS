<?php

use App\Support\Portal;

use function Livewire\Volt\{layout, mount, state, title};

layout('layouts.app');
title(__('Import staff'));

state(['portal' => 'admin'])->locked();

mount(function () {
    $this->portal = Portal::current() === 'hr' ? 'hr' : 'admin';
});

?>

<div>
    <x-page-header :title="__('Import staff')"
                   :breadcrumbs="[Portal::label($portal) => route(Portal::dashboardRoute($portal)), __('Staff') => Portal::route($portal, 'staff.index'), __('Import') => null]" />

    <livewire:imports.wizard kind="staff" :done-url="Portal::route($portal, 'staff.index')" />
</div>
