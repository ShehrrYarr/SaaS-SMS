<?php

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Import students'));

?>

<div>
    <x-page-header :title="__('Import students')"
                   :breadcrumbs="[__('School Admin') => route('admin.dashboard'), __('Students') => route('admin.students.index'), __('Import') => null]" />

    <livewire:imports.wizard kind="students" :done-url="route('admin.students.index')" />
</div>
