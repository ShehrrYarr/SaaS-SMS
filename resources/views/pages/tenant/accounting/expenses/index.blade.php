<?php

use App\Tables\LedgerEntriesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Expenses'));

?>

<x-list-page :title="__('Expenses')" :section="__('Ledger')" :table="LedgerEntriesTable::class" :params="['type' => 'expense']" portal="accounting"
             create="forms.ledger-entry" :create-params="['type' => 'expense']" :create-label="__('Record expense')" create-permission="expenses.create">
    <x-slot:actions>
        <a href="{{ route('accounting.categories.index') }}" wire:navigate class="btn btn-light"><i class="ri-price-tag-3-line me-1"></i>{{ __('Categories') }}</a>
    </x-slot:actions>
    <p class="text-muted mt-n3 mb-4">{{ __('Money going out. Salaries are added automatically when payroll is marked paid.') }}</p>
</x-list-page>
