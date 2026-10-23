<?php

use App\Tables\SubjectsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Subjects'));

?>

<x-list-page :title="__('Subjects')" :section="__('Academic setup')" :table="SubjectsTable::class"
             create="forms.subject" :create-label="__('New subject')" create-permission="subjects.create">
    @can('subjects.allocate')
        <x-slot:actions>
            <a href="{{ route('admin.subjects.allocation') }}" wire:navigate class="btn btn-light">
                <i class="ri-node-tree me-1"></i>{{ __('Allocate to classes') }}
            </a>
        </x-slot:actions>
    @endcan
</x-list-page>
