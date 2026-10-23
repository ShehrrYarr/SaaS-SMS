<?php

use App\Tables\ExaminationsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Examinations'));

?>

<x-list-page :title="__('Examinations')" :section="__('Academics')" :table="ExaminationsTable::class"
             create="forms.examination" :create-label="__('New examination')" create-permission="examinations.create">
    <p class="text-muted mt-n3 mb-4">{{ __('Set up each exam\'s date sheet, follow marks entry, then publish results and print report cards.') }}</p>
</x-list-page>
