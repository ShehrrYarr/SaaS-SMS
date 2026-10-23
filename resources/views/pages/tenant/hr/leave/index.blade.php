<?php

use App\Tables\StaffLeaveTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Leave requests'));

?>

<x-list-page :title="__('Leave requests')" :section="__('Leave')" :table="StaffLeaveTable::class" :params="['scope' => 'hr']" portal="hr"
             create="forms.staff-leave" :create-params="['as' => 'hr']" :create-label="__('Record leave')" create-permission="leave.approve">
    <p class="text-muted mt-n3 mb-4">{{ __('Leave requested by staff. Approved days show as on leave in the staff register; unpaid leave is deducted in payroll. Nobody can approve their own leave.') }}</p>
</x-list-page>
