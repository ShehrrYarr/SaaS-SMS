<?php

/*
 * Staff directory, in the School Admin portal (admin/staff) and the HR
 * portal (hr/staff).
 */

use App\Services\Billing\PlanLimits;
use App\Support\Portal;
use App\Tables\StaffTable;

use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts.app');
title(__('Staff'));

state(['portal' => 'admin'])->locked();

mount(function () {
    $this->portal = Portal::current() === 'hr' ? 'hr' : 'admin';
});

$usage = computed(fn () => app(PlanLimits::class)->usage(tenant())['staff']);

?>

<x-list-page :title="__('Staff')" :section="__('People')" :table="StaffTable::class" :params="['portal' => $portal]" :portal="$portal">
    <x-slot:actions>
        @can('staff.import')
            @if (Route::has($portal.'.staff.import'))
                <a href="{{ Portal::route($portal, 'staff.import') }}" wire:navigate class="btn btn-light"><i class="ri-file-upload-line me-1"></i>{{ __('Import') }}</a>
            @endif
        @endcan
        @can('staff.create')
            <a href="{{ Portal::route($portal, 'staff.create') }}" wire:navigate class="btn btn-primary"><i class="ri-user-add-line me-1"></i>{{ __('Add staff') }}</a>
        @endcan
    </x-slot:actions>

    @if ($this->usage['limit'] !== null)
        <div class="card mb-4">
            <div class="card-body py-3">
                <x-usage-meter :label="__('Staff on your plan')" :used="$this->usage['used']" :limit="$this->usage['limit']" />
            </div>
        </div>
    @endif
</x-list-page>
