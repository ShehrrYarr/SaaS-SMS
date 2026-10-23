<?php

use App\Tables\PayslipsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Salary slips'));

?>

<x-list-page :title="__('Salary slips')" :section="__('Payroll')" :table="PayslipsTable::class" :params="['scope' => 'hr']" portal="hr">
    <p class="text-muted mt-n3 mb-4">{{ __('Slips for approved and paid payroll. Staff see their own once their salary is marked paid.') }}</p>
</x-list-page>
