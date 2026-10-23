<?php

use App\Tables\TermsTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Terms'));

?>

<x-list-page :title="__('Terms')" :section="__('Academic setup')" :table="TermsTable::class"
             create="forms.term" :create-label="__('New term')" create-permission="terms.create" />
