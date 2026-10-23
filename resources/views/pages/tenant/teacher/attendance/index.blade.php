<?php

/*
 * Teacher portal: take today's register for one of my sections.
 */

use App\Services\Attendance\StudentAttendanceRegister;

use function Livewire\Volt\{computed, layout, mount, state, title};

layout('layouts.app');
title(__('Attendance'));

state(['group' => '', 'date' => ''])->url();

$groups = computed(fn () => app(StudentAttendanceRegister::class)->groupsFor(auth()->user()));

mount(function () {
    $this->authorize('attendance.mark');

    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->date)) {
        $this->date = tenant()->today()->toDateString();
    }

    if (! $this->groups->has($this->group)) {
        $this->group = (string) ($this->groups->keys()->first() ?? '');
    }
});

?>

@php($valid = $group && $this->groups->has($group) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))

<div>
    <x-page-header :title="__('Attendance')" :breadcrumbs="[__('Teacher') => route('teacher.dashboard'), __('Attendance') => null]" />

    @if ($this->groups->isEmpty())
        <div class="card mb-0">
            <x-empty-state icon="ri-calendar-check-line" :title="__('No sections assigned to you')"
                           :message="__('You take attendance for sections you are class teacher of or teach a subject in. Ask the school admin to assign you.')" />
        </div>
    @else
        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap align-items-end gap-3">
                <div class="flex-grow-1" style="max-width: 22rem">
                    <label class="form-label" for="register-group">{{ __('Section') }}</label>
                    <select id="register-group" class="form-select" wire:model.live="group">
                        @foreach ($this->groups as $key => $item)
                            <option value="{{ $key }}">{{ $item['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="register-date">{{ __('Date') }}</label>
                    <input type="date" id="register-date" class="form-control" wire:model.live="date" max="{{ tenant()->today()->toDateString() }}">
                </div>
                @if ($date !== tenant()->today()->toDateString())
                    <button type="button" class="btn btn-light" wire:click="$set('date', '{{ tenant()->today()->toDateString() }}')">{{ __('Today') }}</button>
                @endif
            </div>
        </div>

        @if ($valid)
            <livewire:attendance.student-register :group-key="$group" :date="$date" :key="'register-'.$group.'-'.$date" />
        @endif
    @endif
</div>
