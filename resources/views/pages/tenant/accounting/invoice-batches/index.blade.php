<?php

/*
 * Bulk invoice generation: past and running runs (refreshing while one is
 * in progress), and one run's invoices (?batch=).
 */

use App\Enums\BatchStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\InvoiceBatch;
use App\Tables\InvoiceBatchesTable;
use App\Tables\InvoicesTable;

use function Livewire\Volt\{computed, layout, mount, on, state, title, uses};

layout('layouts.app');
title(__('Bulk invoice generation'));

uses([InteractsWithUi::class]);

state(['batch' => null])->url();

mount(function () {
    $this->authorize('invoices.generate');

    if ($this->batch !== null && ! InvoiceBatch::query()->whereKey((int) $this->batch)->exists()) {
        $this->batch = null;
    }
});

on(['batch-started' => fn () => null]);

$running = computed(fn () => InvoiceBatch::query()->whereIn('status', [BatchStatus::Queued, BatchStatus::Processing])->exists());

$selected = computed(fn () => $this->batch ? InvoiceBatch::query()->find((int) $this->batch) : null);

$enabled = computed(fn () => (bool) tenant()->setting('fees.bulk_generation_enabled'));

$poll = function () {
    unset($this->running);
    $this->refreshTables();
};

?>

<div @if ($this->running) wire:poll.3s="poll" @endif>
    <x-page-header :title="__('Bulk invoice generation')"
                   :breadcrumbs="[__('Accounting') => route('accounting.dashboard'), __('Invoices') => route('accounting.invoices.index'), __('Bulk generation') => null]">
        @if ($this->enabled)
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', @js(['component' => 'forms.invoice-batch', 'size' => 'lg']))">
                    <i class="ri-stack-line me-1"></i>{{ __('Generate invoices') }}
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @unless ($this->enabled)
        <div class="alert alert-warning d-flex gap-2">
            <i class="ri-error-warning-line fs-5 lh-1"></i>
            <div>
                {{ __('Bulk invoice generation is switched off for this school.') }}
                @can('settings.edit')
                    <a href="{{ route('admin.settings.index') }}" wire:navigate>{{ __('Change in School settings → Fee options') }}</a>
                @endcan
            </div>
        </div>
    @endunless

    <p class="text-muted mb-4">{{ __('Bill a month\'s fees to every active student of the classes you choose. Discounts and sibling discounts are applied automatically, and nobody is billed twice for the same fee in a month.') }}</p>

    <livewire:components.data-table :table="InvoiceBatchesTable::class" />

    @if ($this->selected)
        <div class="d-flex align-items-center justify-content-between mt-4 mb-3">
            <h5 class="mb-0">{{ __('Invoices from “:title”', ['title' => $this->selected->title]) }}</h5>
            <a href="{{ route('accounting.invoice-batches.index') }}" wire:navigate class="btn btn-sm btn-light">{{ __('Close') }}</a>
        </div>
        <livewire:components.data-table :table="InvoicesTable::class" :params="['batch' => $this->selected->id]" wire:key="batch-invoices-{{ $this->selected->id }}" />
    @endif
</div>
