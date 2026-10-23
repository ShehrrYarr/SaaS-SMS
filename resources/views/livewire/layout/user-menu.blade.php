<?php

use App\Livewire\Actions\Logout;

use function Livewire\Volt\with;

with(fn () => ['user' => auth()->user()]);

$logout = function (Logout $logout) {
    // A full page load: the signed-out pages use a different layout.
    $this->redirect($logout());
};

?>

<div class="dropdown profile-dropdown features-dropdown">
    <button type="button" id="accountMenuButton" class="btn profile-btn shadow-none px-0 hstack gap-0 gap-sm-3" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="avatar-item avatar overflow-hidden">
            @if ($user->avatarUrl())
                <img class="img-fluid" src="{{ $user->avatarUrl() }}" alt="">
            @else
                <span class="avatar-title bg-primary text-white fs-12 fw-semibold w-100 h-100">{{ $user->initials() }}</span>
            @endif
        </span>
        <span class="d-none d-xl-block text-start">
            <span class="h6 d-block fw-semibold mb-0">{{ $user->name }}</span>
            <span class="d-block fs-12 text-muted">{{ $user->roleLabel() }}</span>
        </span>
    </button>

    <div class="dropdown-menu dropdown-menu-end" aria-labelledby="accountMenuButton">
        <div class="dropdown-header">
            <div class="fw-semibold text-body">{{ $user->name }}</div>
            <div class="fs-12 text-muted text-truncate">{{ $user->email ?? $user->username }}</div>
        </div>
        <div class="dropdown-divider"></div>

        @if (tenant())
            <a class="dropdown-item hstack gap-2" href="{{ route('tenant.password.change') }}">
                <i class="ri-lock-password-line"></i>{{ __('Change password') }}
            </a>
            <div class="dropdown-divider"></div>
        @endif

        <button type="button" class="dropdown-item hstack gap-2 text-danger" wire:click="logout">
            <i class="ri-logout-box-r-line"></i>{{ __('Sign out') }}
        </button>
    </div>
</div>
