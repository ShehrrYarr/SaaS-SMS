{{-- Shared sign-in form for school and console sign-in pages (Livewire: login, password, remember, authenticate). --}}
@props(['loginLabel' => __('Email, phone or username')])

<form wire:submit="authenticate" class="form-custom mt-6" novalidate>
    <div class="mb-5">
        <label class="form-label" for="login">{{ $loginLabel }}<span class="text-danger ms-1">*</span></label>
        <input wire:model="login" type="text" id="login" autocomplete="username" autocapitalize="none" autofocus
               @class(['form-control', 'is-invalid' => $errors->has('login')])>
        @error('login')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="mb-5" x-data="{ show: false }">
        <label class="form-label" for="password">{{ __('Password') }}<span class="text-danger ms-1">*</span></label>
        <div class="input-group has-validation">
            <input wire:model="password" type="password" :type="show ? 'text' : 'password'" id="password" autocomplete="current-password"
                   @class(['form-control', 'is-invalid' => $errors->has('password')])>
            <button type="button" class="input-group-text bg-transparent" @click="show = ! show" :aria-label="show ? @js(__('Hide password')) : @js(__('Show password'))">
                <i class="text-muted" :class="show ? 'ri-eye-line' : 'ri-eye-off-line'"></i>
            </button>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="form-check form-check-sm d-flex align-items-center gap-2 mb-5">
        <input wire:model="remember" class="form-check-input" type="checkbox" id="remember">
        <label class="form-check-label" for="remember">{{ __('Keep me signed in') }}</label>
    </div>

    <button type="submit" class="btn btn-primary rounded-2 w-100" wire:loading.attr="disabled" wire:target="authenticate">
        <span wire:loading.remove wire:target="authenticate">{{ __('Sign in') }}</span>
        <span wire:loading wire:target="authenticate"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>{{ __('Signing in…') }}</span>
    </button>

    {{ $slot }}
</form>
