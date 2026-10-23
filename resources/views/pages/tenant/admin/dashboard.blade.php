<?php

use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\ParentProfile;
use App\Models\SchoolClass;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectClass;

use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title(__('Dashboard'));

$stats = computed(fn () => [
    'students' => StudentProfile::count(),
    'staff' => StaffProfile::count(),
    'parents' => ParentProfile::count(),
    'classes' => SchoolClass::count(),
]);

// Setup progress for a newly provisioned school.
$setup = computed(fn () => [
    ['label' => __('Set the current academic year'), 'done' => AcademicYear::current()->exists(), 'route' => 'admin.academic-years.index'],
    ['label' => __('Add a campus'), 'done' => Campus::exists(), 'route' => 'admin.campuses.index'],
    ['label' => __('Create classes and sections'), 'done' => SchoolClass::exists(), 'route' => 'admin.classes.index'],
    ['label' => __('Add subjects'), 'done' => Subject::exists(), 'route' => 'admin.subjects.index'],
    ['label' => __('Allocate subjects to classes'), 'done' => SubjectClass::exists(), 'route' => 'admin.subjects.allocation'],
    ['label' => __('Add staff'), 'done' => StaffProfile::exists(), 'route' => 'admin.staff.index'],
    ['label' => __('Assign class teachers'), 'done' => App\Models\Section::query()->whereNotNull('class_teacher_id')->exists(), 'route' => 'admin.classes.index'],
    ['label' => __('Admit students'), 'done' => StudentProfile::exists(), 'route' => 'admin.students.index'],
]);

?>

<div>
    <x-page-header :title="__('Dashboard')" :breadcrumbs="[__('School Admin') => null, __('Dashboard') => null]" />

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Students')" :value="number_format($this->stats['students'])" icon="ri-graduation-cap-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Staff')" :value="number_format($this->stats['staff'])" icon="ri-user-star-line" color="success" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Parents')" :value="number_format($this->stats['parents'])" icon="ri-parent-line" color="info" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Classes')" :value="number_format($this->stats['classes'])" icon="ri-building-2-line" color="warning" /></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <x-dashboard.welcome />
        </div>

        <div class="col-xl-5">
            @php($completed = collect($this->setup)->where('done', true)->count())
            <div class="card mb-0 h-100">
                <div class="card-header hstack gap-2">
                    <h5 class="card-title mb-0 flex-grow-1">{{ __('School setup') }}</h5>
                    <span class="badge bg-primary-subtle text-primary">{{ $completed }}/{{ count($this->setup) }}</span>
                </div>
                <ul class="list-group list-group-flush">
                    @foreach ($this->setup as $step)
                        <li class="list-group-item hstack gap-3">
                            @if ($step['done'])
                                <i class="ri-checkbox-circle-fill text-success fs-5"></i>
                            @else
                                <i class="ri-checkbox-blank-circle-line text-muted fs-5"></i>
                            @endif
                            @if (! $step['done'] && isset($step['route']) && Route::has($step['route']))
                                <a href="{{ route($step['route']) }}" wire:navigate class="flex-grow-1">{{ $step['label'] }}</a>
                                <i class="ri-arrow-right-s-line text-muted"></i>
                            @else
                                <span @class(['text-muted text-decoration-line-through' => $step['done']])>{{ $step['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <x-notices.latest />
    </div>
</div>
