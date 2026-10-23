<?php

use App\Enums\LeaveStatus;
use App\Enums\StaffAttendanceStatus;
use App\Models\LeaveRequest;
use App\Models\StaffAttendance;
use App\Models\StaffProfile;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Dashboard'));

$stats = computed(fn () => [
    'staff' => StaffProfile::count(),
    'presentToday' => StaffAttendance::query()->whereDate('date', today())
        ->whereIn('status', [StaffAttendanceStatus::Present, StaffAttendanceStatus::Late, StaffAttendanceStatus::HalfDay])->count(),
    'pendingLeave' => LeaveRequest::query()->where('applicant_type', 'staff_profile')->where('status', LeaveStatus::Pending)->count(),
]);

?>

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('HR') => null, __('Dashboard') => null]" />

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-4"><x-stat-card :label="__('Staff')" :value="number_format($this->stats['staff'])" icon="ri-team-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-4"><x-stat-card :label="__('Present today')" :value="number_format($this->stats['presentToday'])" icon="ri-user-follow-line" color="success" /></div>
        <div class="col-sm-6 col-xl-4"><x-stat-card :label="__('Leave awaiting approval')" :value="number_format($this->stats['pendingLeave'])" icon="ri-calendar-close-line" color="warning" /></div>
    </div>

    <x-dashboard.welcome />

    <div class="mt-4">
        <x-notices.latest />
    </div>
</div>
