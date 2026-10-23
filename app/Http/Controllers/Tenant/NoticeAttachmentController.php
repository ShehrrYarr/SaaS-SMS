<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a notice's attachment. Attachments live on a private disk, so
 * they are only ever served to someone allowed to read the notice.
 */
class NoticeAttachmentController extends Controller
{
    public function __invoke(Request $request, int $notice): StreamedResponse
    {
        $record = Notice::query()->findOrFail($notice);

        abort_unless($record->attachment && $record->canBeReadBy($request->user()), 404);
        abort_unless(Storage::disk(Notice::DISK)->exists($record->attachment), 404);

        return Storage::disk(Notice::DISK)->download($record->attachment, $record->attachmentName());
    }
}
