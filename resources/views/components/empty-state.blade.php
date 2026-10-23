@props(['icon' => 'ri-inbox-2-line', 'title', 'message' => null])

<div {{ $attributes->class('text-center py-8 px-4') }}>
    <span class="avatar-item avatar-xl avatar-title bg-light text-muted fs-2 mx-auto mb-4"><i class="{{ $icon }}"></i></span>
    <h6 class="mb-1">{{ $title }}</h6>
    @if ($message)
        <p class="text-muted mb-0 mx-auto" style="max-width: 28rem">{{ $message }}</p>
    @endif
    {{ $slot }}
</div>
