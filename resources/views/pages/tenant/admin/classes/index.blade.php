<?php

use App\Tables\ClassesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Classes & sections'));

?>

<x-list-page :title="__('Classes & sections')" :section="__('Academic setup')" :table="ClassesTable::class"
             create="forms.school-class" :create-label="__('New class')" create-permission="classes.create" create-size="lg" />
