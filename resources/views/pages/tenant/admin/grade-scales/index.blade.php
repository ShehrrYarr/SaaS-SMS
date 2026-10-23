<?php

use App\Tables\GradeScalesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Grade scales'));

?>

<x-list-page :title="__('Grade scales')" :section="__('Academics')" :table="GradeScalesTable::class"
             create="forms.grade-scale" :create-label="__('New grade scale')" create-permission="grade-scales.manage" create-size="lg">
    <p class="text-muted mt-n3 mb-4">{{ __('How percentages turn into grades. Each examination uses one scale; the default applies when none is chosen.') }}</p>
</x-list-page>
