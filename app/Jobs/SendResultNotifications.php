<?php

namespace App\Jobs;

use App\Enums\EnrollmentStatus;
use App\Enums\UserStatus;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\ParentProfile;
use App\Models\StudentEnrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the students of an examination's classes, and their parents, that
 * results are out. Queued; the school travels with the job.
 */
class SendResultNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $examinationId) {}

    public function handle(): void
    {
        $exam = Examination::query()->find($this->examinationId);

        if (! $exam || ! $exam->isPublished()) {
            return;
        }

        $classIds = ExamSchedule::query()->where('examination_id', $exam->id)->distinct()->pluck('class_id');

        $studentIds = StudentEnrollment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->whereIn('class_id', $classIds)
            ->whereNotIn('status', [EnrollmentStatus::Left, EnrollmentStatus::Transferred])
            ->pluck('student_profile_id');

        $studentUsers = StudentProfile::query()->whereIn('id', $studentIds)->select('user_id');
        $parentUsers = ParentProfile::query()
            ->whereHas('children', fn ($query) => $query->whereIn('student_profiles.id', $studentIds))
            ->select('user_id');

        $notification = new SystemNotification(
            title: __('Results published: :exam', ['exam' => $exam->name]),
            message: __('Report cards are ready to view.'),
            url: route('tenant.home'),
            icon: 'ri-award-line',
            color: 'success',
        );

        User::query()
            ->where('status', UserStatus::Active)
            ->where(fn ($query) => $query->whereIn('id', $studentUsers)->orWhereIn('id', $parentUsers))
            ->chunkById(500, fn ($users) => Notification::send($users, $notification));
    }
}
