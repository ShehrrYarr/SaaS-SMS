<?php

use App\Tables\InvoicesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Invoices'));

?>

<x-list-page :title="__('Invoices')" :section="__('Fees')" :table="InvoicesTable::class" portal="accounting"
             create="forms.invoice" :create-label="__('New invoice')" create-permission="invoices.create" create-size="lg">
    @can('invoices.generate')
        <x-slot:actions>
            <a href="{{ route('accounting.invoice-batches.index') }}" wire:navigate class="btn btn-light"><i class="ri-stack-line me-1"></i>{{ __('Bulk generation') }}</a>
        </x-slot:actions>
    @endcan
    <p class="text-muted mt-n3 mb-4">{{ __('Overdue invoices are marked every night, and late fees are added where the school charges them.') }}</p>
</x-list-page>
