<?php

/*
 * A staff member's own library loans, in whichever staff portal they use.
 */

use App\Services\Portals\StudentOverview;
use App\Support\Portal;

use function Livewire\Volt\{computed, layout, mount, title};

layout('layouts.app');
title(__('My library books'));

mount(fn () => $this->authorize('view-own-library'));

$loans = computed(fn () => app(StudentOverview::class)->library(auth()->user()));

?>

@php($portal = Portal::current() ?? 'admin')

<div>
    <x-page-header :title="__('My library books')" :breadcrumbs="[Portal::label($portal) => route(Portal::dashboardRoute($portal)), __('My library books') => null]" />

    <x-library.loans :data="$this->loans" />
</div>
