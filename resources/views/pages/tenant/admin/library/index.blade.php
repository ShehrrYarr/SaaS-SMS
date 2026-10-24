<?php

/*
 * The library: catalogue, books out on loan, returned books and fines,
 * with the desk's actions (issue, return, renew, report lost, settle fines)
 * and the lending rules.
 */

use App\Enums\FineStatus;
use App\Models\BookIssue;
use App\Models\LibraryBook;
use App\Services\Library\Library;
use App\Support\Billing\Money;
use App\Tables\BookIssuesTable;
use App\Tables\LibraryBooksTable;

use function Livewire\Volt\{computed, layout, mount, on, state, title};

layout('layouts.app');
title(__('Library'));

state(['tab' => 'books'])->url();

mount(function () {
    $this->authorize('library.view');

    if (! in_array($this->tab, ['books', 'loans', 'history', 'fines'], true)) {
        $this->tab = 'books';
    }
});

on([
    'library-updated' => function () {
        unset($this->stats);
    },
    'refresh-tables' => function () {
        unset($this->stats);
    },
    'table-action-done' => function () {
        unset($this->stats);
    },
]);

$stats = computed(fn () => [
    'titles' => LibraryBook::query()->count(),
    'copies' => (int) LibraryBook::query()->sum('quantity'),
    'out' => BookIssue::query()->out()->count(),
    'overdue' => BookIssue::query()->overdue()->count(),
    'fines' => (float) BookIssue::query()->where('fine_status', FineStatus::Unpaid)->sum('fine_amount'),
]);

?>

@php
    $stats = $this->stats;
    $rules = Library::settings();
    $tabs = [
        'books' => __('Catalogue'),
        'loans' => __('On loan').($stats['out'] ? ' ('.$stats['out'].')' : ''),
        'history' => __('Returned & lost'),
        'fines' => __('Fines'),
    ];
@endphp

<div>
    <x-page-header :title="__('Library')" :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Operations') => null, __('Library') => null]">
        <x-slot:actions>
            @can('library.manage')
                <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', { component: 'forms.library-settings' })">
                    <i class="ri-settings-4-line me-1"></i>{{ __('Rules') }}
                </button>
                <button type="button" class="btn btn-light" wire:click="$dispatch('open-modal', { component: 'forms.library-book' })">
                    <i class="ri-add-line me-1"></i>{{ __('Add a book') }}
                </button>
            @endcan
            @can('library.issue')
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', { component: 'forms.book-issue' })">
                    <i class="ri-arrow-right-up-line me-1"></i>{{ __('Issue a book') }}
                </button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Titles')" :value="number_format($stats['titles'])" icon="ri-book-2-line"
                         :hint="trans_choice(':count copy|:count copies', $stats['copies'], ['count' => number_format($stats['copies'])])" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('On loan')" :value="number_format($stats['out'])" icon="ri-arrow-right-up-line" color="info"
                         :hint="__(':days-day loans', ['days' => $rules['loan_days']])" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Overdue')" :value="number_format($stats['overdue'])" icon="ri-alarm-warning-line" :color="$stats['overdue'] > 0 ? 'danger' : 'success'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card :label="__('Fines owed')" :value="Money::school($stats['fines'])" icon="ri-money-dollar-circle-line" :color="$stats['fines'] > 0 ? 'warning' : 'secondary'"
                         :hint="$rules['fine_per_day'] > 0 ? __(':amount a day late', ['amount' => Money::school($rules['fine_per_day'])]) : __('No overdue fines')" />
        </div>
    </div>

    <ul class="nav nav-tabs mb-4" role="tablist">
        @foreach ($tabs as $key => $label)
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['nav-link', 'active' => $tab === $key])>{{ $label }}</button>
            </li>
        @endforeach
    </ul>

    @if ($tab === 'books')
        <livewire:components.data-table :table="LibraryBooksTable::class" wire:key="library-books" />
    @else
        <livewire:components.data-table :table="BookIssuesTable::class" :params="['scope' => $tab]" wire:key="library-{{ $tab }}" />
    @endif
</div>
