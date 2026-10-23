<?php

/*
 * Header bell: the signed-in user's in-app (database) notifications.
 * Send one with App\Notifications\SystemNotification.
 */

use function Livewire\Volt\{computed};

$unreadCount = computed(fn () => auth()->user()->unreadNotifications()->count());

$latest = computed(fn () => auth()->user()->notifications()->latest()->limit(8)->get());

$open = function (string $id) {
    $notification = auth()->user()->notifications()->findOrFail($id);
    $notification->markAsRead();

    $url = $notification->data['url'] ?? null;

    // Only follow links inside this app.
    if (is_string($url) && str_starts_with($url, url('/'))) {
        $this->redirect($url, navigate: true);
    }
};

$markAllRead = function () {
    auth()->user()->unreadNotifications()->update(['read_at' => now()]);
};

?>

<div class="dropdown features-dropdown" wire:poll.60s.visible>
    <button type="button" class="btn icon-btn btn-text-primary rounded-circle position-relative" wire:ignore.self
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
            aria-label="{{ trans_choice('{0} Notifications|{1} Notifications, :count unread|[2,*] Notifications, :count unread', $this->unreadCount, ['count' => $this->unreadCount]) }}">
        <i class="ri-notification-2-line fs-20"></i>
        @if ($this->unreadCount)
            <span class="position-absolute translate-middle badge rounded-pill p-1 min-w-20px text-bg-danger" style="top: 8px; left: 75%">
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end p-0" wire:ignore.self>
        <div class="dropdown-header d-flex align-items-center py-3 border-bottom">
            <h6 class="mb-0 flex-grow-1 text-body">{{ __('Notifications') }}</h6>
            @if ($this->unreadCount)
                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" wire:click="markAllRead">{{ __('Mark all as read') }}</button>
            @endif
        </div>

        @if ($this->latest->isEmpty())
            <div class="text-center text-muted py-6 px-4">
                <i class="ri-notification-off-line fs-3 d-block mb-2"></i>
                {{ __('You\'re all caught up.') }}
            </div>
        @else
            <ul class="list-group list-group-flush overflow-auto" style="max-height: 22rem">
                @foreach ($this->latest as $notification)
                    @php($data = $notification->data)
                    <li class="list-group-item list-group-item-action border-0 border-bottom" wire:key="notification-{{ $notification->id }}">
                        <button type="button" class="btn p-0 border-0 text-start w-100 d-flex gap-3" wire:click="open(@js($notification->id))">
                            <span class="avatar-item avatar-sm avatar-title flex-shrink-0 bg-{{ $data['color'] ?? 'primary' }}-subtle text-{{ $data['color'] ?? 'primary' }}">
                                <i class="{{ $data['icon'] ?? 'ri-notification-3-line' }}"></i>
                            </span>
                            <span class="flex-grow-1 min-w-0">
                                <span @class(['d-block small text-body', 'fw-semibold' => $notification->unread()])>{{ $data['title'] ?? __('Notification') }}</span>
                                @if (! empty($data['message']))
                                    <span class="d-block small text-muted text-wrap">{{ $data['message'] }}</span>
                                @endif
                                <span class="d-block fs-12 text-muted mt-1">{{ $notification->created_at->diffForHumans() }}</span>
                            </span>
                            @if ($notification->unread())
                                <span class="flex-shrink-0 mt-1 rounded-circle bg-primary" style="width: 8px; height: 8px" title="{{ __('Unread') }}"></span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
