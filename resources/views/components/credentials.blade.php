{{--
    Sign-in details shown once after an account is created or its password
    reset. There is no email, so the administrator copies them from here.

        <x-credentials :url="$school->loginUrl()" :username="$user->username" :password="$password" />
--}}
@props(['url' => null, 'username', 'password'])

@php
    $rows = array_filter([
        __('Sign-in address') => $url,
        __('Username') => $username,
        __('Temporary password') => $password,
    ], fn ($value) => filled($value));

    $all = collect($rows)->map(fn ($value, $label) => "{$label}: {$value}")->implode("\n");
@endphp

<div {{ $attributes->class('credentials') }} x-data="{ copied: null, copy(text, key) { navigator.clipboard?.writeText(text).then(() => { this.copied = key; setTimeout(() => this.copied = null, 1500) }) } }">
    <div class="alert alert-warning d-flex gap-2 align-items-start fs-13 mb-3" role="alert">
        <i class="ri-error-warning-line fs-5 lh-1"></i>
        <span>{{ __('Copy these details now: the password is shown only once. The user must choose a new password when they first sign in.') }}</span>
    </div>

    <dl class="credentials-list mb-3">
        @foreach ($rows as $label => $value)
            <div class="credentials-row">
                <dt>{{ $label }}</dt>
                <dd>
                    <code class="credentials-value" @if ($loop->last) data-secret @endif>{{ $value }}</code>
                    <button type="button" class="btn btn-subtle-secondary icon-btn-sm flex-shrink-0" x-on:click="copy(@js($value), {{ $loop->index }})"
                            :aria-label="copied === {{ $loop->index }} ? @js(__('Copied')) : @js(__('Copy :label', ['label' => Str::lower($label)]))">
                        <i :class="copied === {{ $loop->index }} ? 'ri-check-line text-success' : 'ri-file-copy-line'"></i>
                    </button>
                </dd>
            </div>
        @endforeach
    </dl>

    <button type="button" class="btn btn-sm btn-subtle-primary" x-on:click="copy(@js($all), 'all')">
        <i class="me-1" :class="copied === 'all' ? 'ri-check-line' : 'ri-file-copy-2-line'"></i>
        <span x-text="copied === 'all' ? @js(__('Copied')) : @js(__('Copy all'))">{{ __('Copy all') }}</span>
    </button>
</div>
