<?php

use App\Tables\AcademicYearsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Academic years'));

?>

<x-list-page :title="__('Academic years')" :section="__('Academic setup')" :table="AcademicYearsTable::class"
             create="forms.academic-year" :create-label="__('New academic year')" create-permission="academic-years.create" />
