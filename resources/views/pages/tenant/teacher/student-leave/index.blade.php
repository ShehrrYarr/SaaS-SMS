<?php

use App\Tables\StudentLeaveTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Student leave'));

?>

<x-list-page :title="__('Student leave')" :table="StudentLeaveTable::class" :params="['scope' => 'reviewer']" portal="teacher"
             create="forms.student-leave" :create-params="['as' => 'school']" :create-label="__('Record leave')" create-permission="student-leave.approve">
    <p class="text-muted mt-n3 mb-4">{{ __('Leave requests for students in the sections you are class teacher of. Approved days show as excused in the register.') }}</p>
</x-list-page>
