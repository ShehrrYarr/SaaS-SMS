<?php

use App\Tables\LeaveTypesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Leave types'));

?>

<x-list-page :title="__('Leave types')" :section="__('Leave')" :table="LeaveTypesTable::class" portal="hr"
             create="forms.leave-type" :create-label="__('New leave type')" create-permission="leave.manage">
    <p class="text-muted mt-n3 mb-4">{{ __('Kinds of leave staff and students can apply for. Student leave is approved by the class teacher.') }}</p>
</x-list-page>
