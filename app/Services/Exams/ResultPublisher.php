<?php

namespace App\Services\Exams;

use App\Enums\ExamStatus;
use App\Jobs\SendResultNotifications;
use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\Mark;
use App\Models\User;
use App\Tables\ActionFailed;
use Illuminate\Support\Facades\DB;

/**
 * Publishing results makes them visible to students and parents and locks
 * the marks; unpublishing reopens them for corrections.
 */
class ResultPublisher
{
    /**
     * @throws ActionFailed
     */
    public function publish(Examination $exam, User $by): void
    {
        $scheduleIds = ExamSchedule::query()->where('examination_id', $exam->id)->pluck('id');

        if ($scheduleIds->isEmpty() || Mark::query()->whereIn('exam_schedule_id', $scheduleIds)->doesntExist()) {
            throw new ActionFailed(__('Enter some marks before publishing results.'));
        }

        DB::transaction(function () use ($exam, $by) {
            $exam->update(['status' => ExamStatus::Published, 'results_published_at' => now()]);

            activity('examination')->performedOn($exam)->causedBy($by)->log('Results published');

            SendResultNotifications::dispatch($exam->id)->afterCommit();
        });
    }

    public function unpublish(Examination $exam, User $by): void
    {
        $exam->update(['status' => ExamStatus::Completed, 'results_published_at' => null]);

        activity('examination')->performedOn($exam)->causedBy($by)->log('Results unpublished');
    }
}
