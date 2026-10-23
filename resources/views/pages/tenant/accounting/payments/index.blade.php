<?php

use App\Tables\PaymentsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Payments'));

?>

<x-list-page :title="__('Payments')" :section="__('Fees')" :table="PaymentsTable::class" portal="accounting">
    @can('fees.collect')
        <x-slot:actions>
            <a href="{{ route('accounting.collect') }}" wire:navigate class="btn btn-primary"><i class="ri-hand-coin-line me-1"></i>{{ __('Collect fees') }}</a>
        </x-slot:actions>
    @endcan
    <p class="text-muted mt-n3 mb-4">{{ __('Every fee receipt issued. A mistaken payment is voided with a reason rather than deleted, so the receipt sequence stays complete.') }}</p>
</x-list-page>
