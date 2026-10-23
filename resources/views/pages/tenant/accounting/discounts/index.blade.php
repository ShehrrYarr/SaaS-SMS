<?php

/*
 * Discounts & scholarships: the definitions, which students get them, and
 * the sibling discount rules.
 */

use App\Tables\FeeDiscountsTable;
use App\Tables\SiblingDiscountRulesTable;
use App\Tables\StudentDiscountsTable;

use function Livewire\Volt\{layout, mount, state, title};

layout('layouts.app');
title(__('Discounts & scholarships'));

state(['tab' => 'discounts'])->url();

mount(function () {
    $this->authorize('fee-discounts.view');

    if (! in_array($this->tab, ['discounts', 'students', 'siblings'], true)) {
        $this->tab = 'discounts';
    }
});

?>

@php
    $school = tenant();
    $tabs = [
        'discounts' => [__('Discounts'), FeeDiscountsTable::class, 'forms.fee-discount', __('New discount')],
        'students' => [__('Students with discounts'), StudentDiscountsTable::class, 'forms.student-discount', __('Give a discount')],
        'siblings' => [__('Sibling discount'), SiblingDiscountRulesTable::class, 'forms.sibling-rule', __('New rule')],
    ];
    [, $table, $form, $createLabel] = $tabs[$tab];
    $switchedOff = match ($tab) {
        'siblings' => ! $school->setting('fees.sibling_discount_enabled'),
        default => ! $school->setting('fees.discounts_enabled'),
    };
@endphp

<div>
    <x-page-header :title="__('Discounts & scholarships')" :breadcrumbs="[__('Accounting') => route('accounting.dashboard'), __('Fee setup') => null, __('Discounts & scholarships') => null]">
        @can('fee-discounts.manage')
            <x-slot:actions>
                <button type="button" class="btn btn-primary" wire:click="$dispatch('open-modal', @js(['component' => $form]))">
                    <i class="ri-add-line me-1"></i>{{ $createLabel }}
                </button>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <ul class="nav nav-tabs mb-4" role="tablist">
        @foreach ($tabs as $key => [$label])
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['nav-link', 'active' => $tab === $key])>{{ $label }}</button>
            </li>
        @endforeach
    </ul>

    @if ($switchedOff)
        <div class="alert alert-warning d-flex gap-2 align-items-start">
            <i class="ri-error-warning-line fs-5 lh-1"></i>
            <div>
                {{ $tab === 'siblings' ? __('The sibling discount is switched off, so these rules aren\'t applied to new invoices.') : __('Discounts are switched off, so they aren\'t applied to new invoices.') }}
                @can('settings.edit')
                    <a href="{{ route('admin.settings.index') }}" wire:navigate>{{ __('Change in School settings → Fee options') }}</a>
                @endcan
            </div>
        </div>
    @endif

    <p class="text-muted mb-4">
        @switch($tab)
            @case('students') {{ __('Discounts given to individual students. They apply to invoices raised while the discount is valid.') }} @break
            @case('siblings') {{ __('Reductions for brothers and sisters. Children who share a parent are counted eldest first, and the first child pays in full.') }} @break
            @default {{ __('Discounts and scholarships, as a percentage or a fixed amount. Give them to students on the next tab.') }}
        @endswitch
    </p>

    <livewire:components.data-table :table="$table" wire:key="discounts-{{ $tab }}" />
</div>
