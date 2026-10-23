<?php

use App\Tables\CampusesTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Campuses'));

?>

<x-list-page :title="__('Campuses')" :section="__('Academic setup')" :table="CampusesTable::class"
             create="forms.campus" :create-label="__('New campus')" create-permission="campuses.create" />
