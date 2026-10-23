<?php

use App\Tables\FinanceCategoriesTable;

use function Livewire\Volt\{layout, mount, title};

layout('layouts.app');
title(__('Finance categories'));

mount(fn () => abort_unless(auth()->user()->canAny(['income.view', 'expenses.view']), 403));

?>

<x-list-page :title="__('Finance categories')" :section="__('Ledger')" :table="FinanceCategoriesTable::class" portal="accounting"
             create="forms.finance-category" :create-label="__('New category')"
             :create-permission="auth()->user()->canAny(['income.edit', 'expenses.edit']) ? null : 'income.edit'">
    <p class="text-muted mt-n3 mb-4">{{ __('Headings for income and expenses, used to group them in reports. Fee Collection and Salaries receive automatic entries.') }}</p>
</x-list-page>
