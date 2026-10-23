<?php

namespace App\Jobs;

use App\Enums\UserStatus;
use App\Models\Homework;
use App\Models\StudentProfile;
use App\Notifications\SystemNotification;
use App\Services\Homework\Homeworks;
use App\Support\SelfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the students a homework was set for, and their parents, that it is
 * out. Queued; the school travels with the job.
 */
class SendHomeworkNotifications implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $homeworkId) {}

    public function handle(Homeworks $homeworks): void
    {
        $homework = Homework::query()->with(['subjectClass.subject', 'subjectClass.schoolClass'])->find($this->homeworkId);

        if (! $homework || $homework->isDraft()) {
            return;
        }

        $title = __('New homework: :title', ['title' => $homework->title]);
        $message = __(':subject · due :due', ['subject' => $homework->subjectClass?->subject?->name, 'due' => $homework->dueLabel()]);

        $homeworks->roster($homework)->load('parents.user')->each(function (StudentProfile $student) use ($homework, $title, $message) {
            if ($student->user?->status === UserStatus::Active) {
                $student->user->notify(new SystemNotification($title, $message, route('student.homework.show', $homework->id), 'ri-book-open-line', 'info'));
            }

            foreach ($student->parents as $parent) {
                if ($parent->user?->status === UserStatus::Active) {
                    $parent->user->notify(new SystemNotification(
                        __('New homework for :name: :title', ['name' => $student->user?->name, 'title' => $homework->title]),
                        $message,
                        SelfService::url('parent', 'homework.show', $student, ['homework' => $homework->id]),
                        'ri-book-open-line',
                        'info',
                    ));
                }
            }
        });
    }
}
