<?php

namespace App\Services\Exams;

use App\Models\Examination;
use App\Models\ExamSchedule;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubjectClass;
use App\Tables\ActionFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An examination's date sheet for one class: which subjects are examined,
 * when, where, and out of how many marks.
 */
class ExamSchedules
{
    /**
     * Subjects to offer for a class: those allocated to it in the exam's
     * year, plus any already on the date sheet.
     *
     * @return Collection<int, Subject>
     */
    public function subjectsFor(Examination $exam, SchoolClass $class): Collection
    {
        $allocated = SubjectClass::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('class_id', $class->id)
            ->pluck('subject_id');

        $scheduled = ExamSchedule::query()
            ->where('examination_id', $exam->id)
            ->where('class_id', $class->id)
            ->pluck('subject_id');

        return Subject::query()
            ->whereIn('id', $allocated->merge($scheduled)->unique())
            ->orderBy('name')
            ->get();
    }

    /**
     * Make the class's date sheet exactly $rows. Subjects left out are
     * removed, unless marks have already been entered for them.
     *
     * @param  array<int, array{exam_date?: string|null, start_time?: string|null, end_time?: string|null, classroom_id?: int|string|null, max_marks: float|string, passing_marks: float|string}>  $rows  subject id => schedule
     *
     * @throws ActionFailed
     */
    public function sync(Examination $exam, SchoolClass $class, array $rows): void
    {
        if ($exam->isPublished()) {
            throw new ActionFailed(__('Results are published. Unpublish them before changing the date sheet.'));
        }

        DB::transaction(function () use ($exam, $class, $rows) {
            $existing = ExamSchedule::query()->withTrashed()
                ->with('subject')
                ->withCount('marks')
                ->where('examination_id', $exam->id)
                ->where('class_id', $class->id)
                ->get()
                ->keyBy('subject_id');

            foreach ($existing as $subjectId => $schedule) {
                if (! $schedule->trashed() && ! array_key_exists($subjectId, $rows)) {
                    if ($schedule->marks_count > 0) {
                        throw new ActionFailed(__(':subject already has marks, so it can\'t be taken off the date sheet.', ['subject' => $schedule->subject?->name]));
                    }

                    $schedule->delete();
                }
            }

            foreach ($rows as $subjectId => $row) {
                $values = [
                    'exam_date' => $row['exam_date'] ?: null,
                    'start_time' => $row['start_time'] ?: null,
                    'end_time' => $row['end_time'] ?: null,
                    'classroom_id' => $row['classroom_id'] ?: null,
                    'max_marks' => $row['max_marks'],
                    'passing_marks' => $row['passing_marks'],
                ];

                $schedule = $existing->get($subjectId);

                if ($schedule) {
                    if ($schedule->trashed()) {
                        $schedule->restore();
                    }

                    if ($schedule->marks_count > 0 && (float) $schedule->max_marks !== (float) $values['max_marks']) {
                        throw new ActionFailed(__('Marks for :subject are already entered out of :max. Clear them before changing the total.', [
                            'subject' => $schedule->subject?->name, 'max' => (float) $schedule->max_marks,
                        ]));
                    }

                    $schedule->update($values);
                } else {
                    ExamSchedule::query()->create([...$values, 'examination_id' => $exam->id, 'class_id' => $class->id, 'subject_id' => $subjectId]);
                }
            }
        });
    }
}
