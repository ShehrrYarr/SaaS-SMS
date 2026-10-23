<?php

use App\Tables\DepartmentsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Departments'));

?>

<x-list-page :title="__('Departments')" :section="__('Academic setup')" :table="DepartmentsTable::class"
             create="forms.department" :create-label="__('New department')" create-permission="departments.create" />
