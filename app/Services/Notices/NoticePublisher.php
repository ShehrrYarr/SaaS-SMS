<?php

namespace App\Services\Notices;

use App\Jobs\SendNoticeNotifications;
use App\Models\Notice;
use Illuminate\Support\Carbon;

/**
 * Publishing notices and announcing them once they go live.
 */
class NoticePublisher
{
    /**
     * Publish now, or schedule for later.
     */
    public function publish(Notice $notice, ?Carbon $at = null): void
    {
        $notice->update(['published_at' => $at ?? now()]);

        $this->announceIfLive($notice);

        activity('notice')->performedOn($notice)->causedBy(auth()->user())
            ->log($notice->published_at->isFuture() ? 'Notice scheduled' : 'Notice published');
    }

    /**
     * Take a notice off the board (back to draft). It is announced again if
     * it is published again.
     */
    public function unpublish(Notice $notice): void
    {
        $notice->update(['published_at' => null, 'notified_at' => null]);

        activity('notice')->performedOn($notice)->causedBy(auth()->user())->log('Notice unpublished');
    }

    public function announceIfLive(Notice $notice): void
    {
        if ($notice->isLive() && $notice->notified_at === null) {
            SendNoticeNotifications::dispatch($notice->id)->afterCommit();
        }
    }
}
