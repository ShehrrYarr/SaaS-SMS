<?php

use App\Services\Billing\PlanLimits;
use App\Tables\StudentsTable;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Students'));

$usage = computed(fn () => app(PlanLimits::class)->usage(tenant())['students']);

?>

<x-list-page :title="__('Students')" :section="__('People')" :table="StudentsTable::class">
    <x-slot:actions>
        @can('students.promote')
            <a href="{{ route('admin.students.promote') }}" wire:navigate class="btn btn-light"><i class="ri-arrow-up-double-line me-1"></i>{{ __('Promotion') }}</a>
        @endcan
        @can('students.import')
            <a href="{{ route('admin.students.import') }}" wire:navigate class="btn btn-light"><i class="ri-file-upload-line me-1"></i>{{ __('Import') }}</a>
        @endcan
        @can('students.create')
            <a href="{{ route('admin.students.create') }}" wire:navigate class="btn btn-primary"><i class="ri-user-add-line me-1"></i>{{ __('Admit student') }}</a>
        @endcan
    </x-slot:actions>

    @if ($this->usage['limit'] !== null)
        <div class="card mb-4">
            <div class="card-body py-3">
                <x-usage-meter :label="__('Active students on your plan')" :used="$this->usage['used']" :limit="$this->usage['limit']" />
            </div>
        </div>
    @endif
</x-list-page>
