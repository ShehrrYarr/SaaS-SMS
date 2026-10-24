<?php

namespace App\Services\Notifications;

use App\Enums\UserStatus;
use App\Models\StudentProfile;
use App\Notifications\SystemNotification;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * In-app notices about one student for the student and their parents. The
 * builder gets the portal ("student" or "parent") so each link opens the
 * right portal. Sent once the surrounding transaction commits.
 */
class FamilyNotifier
{
    /**
     * @param  Closure(StudentProfile, string): SystemNotification  $build
     */
    public function notify(int $studentId, Closure $build, bool $student = true, bool $parents = true): void
    {
        DB::afterCommit(function () use ($studentId, $build, $student, $parents) {
            $profile = StudentProfile::query()->with(['user', 'parents.user'])->find($studentId);

            if (! $profile) {
                return;
            }

            if ($student && $profile->user?->status === UserStatus::Active) {
                $profile->user->notify($build($profile, 'student'));
            }

            if ($parents) {
                foreach ($profile->parents as $parent) {
                    if ($parent->user?->status === UserStatus::Active) {
                        $parent->user->notify($build($profile, 'parent'));
                    }
                }
            }
        });
    }
}
