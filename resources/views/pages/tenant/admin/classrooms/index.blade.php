<?php

use App\Tables\ClassroomsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Classrooms'));

?>

<x-list-page :title="__('Classrooms')" :section="__('Academic setup')" :table="ClassroomsTable::class"
             create="forms.classroom" :create-label="__('New room')" create-permission="classrooms.create" />
