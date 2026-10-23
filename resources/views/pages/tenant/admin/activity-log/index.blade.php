<?php

use App\Tables\ActivityLogTable;

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title(__('Activity log'));

?>

<x-list-page :title="__('Activity log')" :section="__('Administration')" :table="ActivityLogTable::class">
    <p class="text-muted mt-n3 mb-4">{{ __('Who changed what, and when. Entries can\'t be edited or deleted.') }}</p>
</x-list-page>
