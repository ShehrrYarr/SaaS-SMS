<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A plain in-app notification for the header bell.
 *
 *     $user->notify(new SystemNotification('Leave approved', 'Your leave for 3 Nov was approved.', route('...')));
 */
class SystemNotification extends Notification
{
    public function __construct(
        public string $title,
        public ?string $message = null,
        public ?string $url = null,
        public string $icon = 'ri-notification-3-line',
        public string $color = 'primary',
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }
}
