<?php

use App\Tables\LedgerEntriesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Income'));

?>

<x-list-page :title="__('Income')" :section="__('Ledger')" :table="LedgerEntriesTable::class" :params="['type' => 'income']" portal="accounting"
             create="forms.ledger-entry" :create-params="['type' => 'income']" :create-label="__('Record income')" create-permission="income.create">
    <x-slot:actions>
        <a href="{{ route('accounting.categories.index') }}" wire:navigate class="btn btn-light"><i class="ri-price-tag-3-line me-1"></i>{{ __('Categories') }}</a>
    </x-slot:actions>
    <p class="text-muted mt-n3 mb-4">{{ __('Money coming in. Fee receipts are added automatically; record donations and other income here.') }}</p>
</x-list-page>
