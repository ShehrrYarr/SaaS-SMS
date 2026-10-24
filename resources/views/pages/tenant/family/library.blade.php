<?php

/*
 * Student and parent portals: library books borrowed, due dates and fines.
 */

use App\Services\Portals\StudentOverview;
use App\Support\SelfService;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Library'));

$student = computed(fn () => SelfService::student());

$loans = computed(fn () => $this->student ? app(StudentOverview::class)->library($this->student->user) : null);

?>

<div>
    <x-page-header :title="__('Library')" :breadcrumbs="SelfService::breadcrumbs([__('Library') => null])" />

    @if (! $this->student)
        <x-family.missing />
    @else
        <x-library.loans :data="$this->loans" />
    @endif
</div>
