<?php

namespace App\Jobs;

use App\Models\Notice;
use App\Notifications\SystemNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Tells everyone a live notice is addressed to, through the header bell.
 *
 * Queued: the school (tenant) travels with the job through Laravel Context,
 * so the notice and its recipients are looked up inside that school.
 */
class SendNoticeNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $noticeId) {}

    public function handle(): void
    {
        $notice = Notice::query()->find($this->noticeId);

        // Deleted, unpublished or already announced since this job was queued.
        if (! $notice || ! $notice->isLive() || $notice->notified_at !== null) {
            return;
        }

        $notice->forceFill(['notified_at' => now()])->saveQuietly();

        $notification = new SystemNotification(
            title: $notice->title,
            message: Str::limit(strip_tags($notice->body), 140),
            url: route('tenant.notices.show', $notice->id),
            icon: 'ri-megaphone-line',
            color: 'primary',
        );

        $notice->recipients()
            ->when($notice->created_by, fn ($query) => $query->whereKeyNot($notice->created_by))
            ->chunkById(500, fn ($users) => Notification::send($users, $notification));
    }
}
