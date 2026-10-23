{{--
    A standard list screen: page header with an optional "New ..." button that
    opens a modal form, then a data table.

        <x-list-page :title="__('Campuses')" :section="__('Academic setup')" :table="App\Tables\CampusesTable::class"
                     create="forms.campus" :create-label="__('New campus')" create-permission="campuses.create" />

    Anything in the slot is shown between the header and the table.
--}}
@props([
    'title',
    'table',
    'section' => null,
    'params' => [],
    'create' => null,
    'createLabel' => null,
    'createPermission' => null,
    'createParams' => [],
    'createSize' => 'md',
])

@php
    $breadcrumbs = [__('School Admin') => route('admin.dashboard')] + ($section ? [$section => null] : []) + [$title => null];
@endphp

<div>
    <x-page-header :title="$title" :breadcrumbs="$breadcrumbs">
        @if ($create && (! $createPermission || auth()->user()?->can($createPermission)))
            <x-slot:actions>
                {{ $actions ?? '' }}
                <button type="button" class="btn btn-primary"
                        wire:click="$dispatch('open-modal', @js(['component' => $create, 'params' => $createParams, 'size' => $createSize]))">
                    <i class="ri-add-line me-1"></i>{{ $createLabel ?? __('New') }}
                </button>
            </x-slot:actions>
        @elseif (isset($actions))
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endif
    </x-page-header>

    {{ $slot }}

    <livewire:components.data-table :table="$table" :params="$params" />
</div>
