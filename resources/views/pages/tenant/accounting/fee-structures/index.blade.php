<?php

use App\Tables\FeeStructuresTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Fee structures'));

?>

<x-list-page :title="__('Fee structures')" :section="__('Fee setup')" :table="FeeStructuresTable::class" portal="accounting"
             create="forms.fee-structure" :create-label="__('New fee')" create-permission="fee-structures.create" create-size="lg">
    <p class="text-muted mt-n3 mb-4">{{ __('The fees your school charges. Monthly fees are billed every month of the academic year; quarterly, half-yearly and yearly fees count from the month the year starts.') }}</p>
</x-list-page>
