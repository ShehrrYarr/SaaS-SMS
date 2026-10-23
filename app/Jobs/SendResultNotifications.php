<?php

namespace App\Jobs;

use App\Enums\EnrollmentStatus;
use App\Enums\UserStatus;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\StudentEnrollment;
use App\Models\StudentProfile;
use App\Notifications\SystemNotification;
use App\Support\SelfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Tells the students of an examination's classes, and their parents (one
 * notice per child), that results are out, linking to the results page.
 * Queued; the school travels with the job.
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

        $message = __('Report cards are ready to view.');

        StudentProfile::query()
            ->with(['user', 'parents.user'])
            ->whereIn('id', $studentIds)
            ->chunkById(200, function (Collection $students) use ($exam, $message) {
                foreach ($students as $student) {
                    if ($student->user?->status === UserStatus::Active) {
                        $student->user->notify(new SystemNotification(
                            __('Results published: :exam', ['exam' => $exam->name]),
                            $message,
                            route('student.results', ['exam' => $exam->id]),
                            'ri-award-line',
                            'success',
                        ));
                    }

                    foreach ($student->parents as $parent) {
                        if ($parent->user?->status === UserStatus::Active) {
                            $parent->user->notify(new SystemNotification(
                                __(':name\'s results are out: :exam', ['name' => $student->user?->name, 'exam' => $exam->name]),
                                $message,
                                SelfService::url('parent', 'results', $student, ['exam' => $exam->id]),
                                'ri-award-line',
                                'success',
                            ));
                        }
                    }
                }
            });
    }
}
