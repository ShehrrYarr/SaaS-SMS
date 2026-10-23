<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Services\Leave\StudentLeave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a leave request's attachment, for whoever asked for the leave,
 * a parent of the student, or someone who may review it.
 */
class LeaveAttachmentController extends Controller
{
    public function __invoke(Request $request, StudentLeave $leave, int $leaveRequest): StreamedResponse
    {
        $record = LeaveRequest::query()->with('applicant')->findOrFail($leaveRequest);
        $user = $request->user();

        $allowed = $record->requested_by === $user->id
            || ($record->applicant instanceof StudentProfile && $record->applicant->parents()->where('parent_profiles.user_id', $user->id)->exists())
            || ($record->applicant instanceof StudentProfile && $leave->canReview($user, $record))
            || ($record->applicant instanceof StaffProfile && ($record->applicant->user_id === $user->id || $user->can('leave.view')))
            || $user->can('leave.manage');

        abort_unless($record->attachment && $allowed, 404);
        abort_unless(Storage::disk(LeaveRequest::DISK)->exists($record->attachment), 404);

        return Storage::disk(LeaveRequest::DISK)->download($record->attachment, 'leave-'.$record->id.'.'.pathinfo($record->attachment, PATHINFO_EXTENSION));
    }
}
