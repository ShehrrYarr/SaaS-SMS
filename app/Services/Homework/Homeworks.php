<?php

namespace App\Services\Homework;

use App\Enums\HomeworkStatus;
use App\Enums\StudentStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserStatus;
use App\Jobs\SendHomeworkNotifications;
use App\Models\AcademicYear;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StudentProfile;
use App\Models\SubjectClass;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\SelfService;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Homework from both sides: teachers set it on their subject allocations
 * (homework.manage: any allocation), publish it to the class and grade the
 * work; students hand it in until it is closed (after the due time it counts
 * as late).
 */
class Homeworks
{
    /**
     * Allocations a user can set homework on, this academic year.
     *
     * @return Collection<int, SubjectClass>
     */
    public function allocationsFor(User $user): Collection
    {
        $year = AcademicYear::query()->current()->value('id');

        if (! $year) {
            return collect();
        }

        $query = SubjectClass::query()->with(['subject', 'schoolClass', 'section', 'teacher.user'])->where('academic_year_id', $year);

        if (! $user->can('homework.manage')) {
            $query->where('teacher_id', $user->staffProfile()->value('id') ?? 0);
        }

        return $query->get()
            ->filter(fn (SubjectClass $allocation) => $allocation->schoolClass && $allocation->subject)
            ->sortBy(fn (SubjectClass $allocation) => [$allocation->schoolClass->numeric_level ?? 999, $allocation->schoolClass->name, $allocation->section?->name, $allocation->subject->name])
            ->values();
    }

    /**
     * Homework a staff member can see in the teacher portal: on allocations
     * they teach, or all of it with homework.manage.
     *
     * @return Builder<Homework>
     */
    public function visibleTo(User $user): Builder
    {
        $query = Homework::query();

        if (! $user->can('homework.manage')) {
            $query->whereIn('subject_class_id', SubjectClass::withTrashed()->where('teacher_id', $user->staffProfile()->value('id') ?? 0)->select('id'));
        }

        return $query;
    }

    /**
     * Whether a staff member looks after this homework (edits, publishes,
     * grades it): they teach the allocation, or have homework.manage.
     */
    public function looksAfter(User $user, Homework $homework): bool
    {
        if ($user->can('homework.manage')) {
            return true;
        }

        $staffId = $user->staffProfile()->value('id');

        return $staffId !== null && $homework->subjectClass?->teacher_id === $staffId;
    }

    /**
     * Students who should do the homework: active students now in the class,
     * in its section (or any section the allocation covers), by roll number.
     *
     * @return Collection<int, StudentProfile>
     */
    public function roster(Homework $homework): Collection
    {
        $allocation = $homework->subjectClass;

        if (! $allocation) {
            return collect();
        }

        $section = $homework->targetSectionId();

        return StudentProfile::query()
            ->with('user')
            ->where('class_id', $allocation->class_id)
            ->when($section, fn (Builder $query) => $query->where('section_id', $section))
            ->where('status', StudentStatus::Active)
            ->get()
            ->sortBy(fn (StudentProfile $student) => [
                $student->roll_number === null ? 1 : 0,
                str_pad((string) $student->roll_number, 12, '0', STR_PAD_LEFT),
                mb_strtolower((string) $student->user?->name),
            ])
            ->values();
    }

    /**
     * Whether the homework was set for this student's class and section.
     */
    public function isFor(Homework $homework, StudentProfile $student): bool
    {
        return Homework::query()->whereKey($homework->id)->forStudent($student)->exists();
    }

    /**
     * @return array{expected: int, handed_in: int, graded: int, late: int}
     */
    public function summary(Homework $homework): array
    {
        $roster = $this->roster($homework)->modelKeys();
        $counts = HomeworkSubmission::query()->where('homework_id', $homework->id)->whereIn('student_profile_id', $roster)
            ->selectRaw('status, count(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');

        $late = (int) ($counts[SubmissionStatus::Late->value] ?? 0);
        $graded = (int) ($counts[SubmissionStatus::Graded->value] ?? 0);

        return [
            'expected' => count($roster),
            'handed_in' => (int) ($counts[SubmissionStatus::Submitted->value] ?? 0) + $late,
            'graded' => $graded,
            'late' => $late,
        ];
    }

    /**
     * Publish a draft: students see it, and they and their parents are told.
     */
    public function publish(Homework $homework): void
    {
        if (! $homework->isDraft()) {
            return;
        }

        $first = $homework->published_at === null;

        $homework->update(['status' => HomeworkStatus::Published, 'published_at' => $homework->published_at ?? now()]);

        if ($first) {
            SendHomeworkNotifications::dispatch($homework->id)->afterCommit();
        }
    }

    /**
     * Stop taking submissions.
     */
    public function close(Homework $homework): void
    {
        if ($homework->isPublished()) {
            $homework->update(['status' => HomeworkStatus::Closed]);
        }
    }

    public function reopen(Homework $homework): void
    {
        if ($homework->isClosed()) {
            $homework->update(['status' => HomeworkStatus::Published]);
        }
    }

    /**
     * Delete homework. Work already handed in keeps it as a soft-deleted
     * record; otherwise it goes for good, with its files.
     */
    public function delete(Homework $homework): void
    {
        $homework->submissions()->exists() ? $homework->delete() : $homework->forceDelete();
    }

    /**
     * Store a homework's own attachment, replacing any previous one.
     */
    public function attach(Homework $homework, ?UploadedFile $file, bool $remove = false): void
    {
        if (! $file && ! $remove) {
            return;
        }

        if ($homework->attachment) {
            Storage::disk(Homework::DISK)->delete($homework->attachment);
        }

        $homework->update(['attachment' => $file ? $this->store($file, "schools/{$homework->school_id}/homework/{$homework->id}") : null]);
    }

    /**
     * A student hands in (or hands in again) their work. After the due time
     * it is marked late; once graded it can't be changed.
     */
    public function submit(Homework $homework, StudentProfile $student, ?string $content, ?UploadedFile $file, bool $removeFile = false): HomeworkSubmission
    {
        throw_unless($this->isFor($homework, $student), ActionFailed::class, __('This homework was not set for your class.'));
        throw_unless($homework->acceptsSubmissions(), ActionFailed::class, __('This homework is closed to new submissions.'));

        return DB::transaction(function () use ($homework, $student, $content, $file, $removeFile) {
            $submission = HomeworkSubmission::query()->lockForUpdate()->firstOrNew([
                'homework_id' => $homework->id,
                'student_profile_id' => $student->id,
            ]);

            throw_if($submission->isGraded(), ActionFailed::class, __('Your work has already been graded.'));

            $attachment = $submission->attachment;

            if (($file || $removeFile) && $attachment) {
                Storage::disk(Homework::DISK)->delete($attachment);
                $attachment = null;
            }

            if ($file) {
                $attachment = $this->store($file, "schools/{$homework->school_id}/homework/{$homework->id}/submissions/{$student->id}");
            }

            $content = filled($content) ? trim($content) : null;

            throw_if($content === null && $attachment === null, ActionFailed::class, __('Write an answer or attach a file.'));

            $resubmitted = $submission->exists;

            $submission->fill([
                'content' => $content,
                'attachment' => $attachment,
                'submitted_at' => now(),
                'status' => $homework->isPastDue() ? SubmissionStatus::Late : SubmissionStatus::Submitted,
            ])->save();

            activity('homework_submission')->performedOn($submission)->causedBy(auth()->user())
                ->withProperties(['homework' => $homework->title, 'student' => $student->user?->name, 'status' => $submission->status->value])
                ->log($resubmitted ? 'Homework handed in again' : 'Homework handed in');

            return $submission;
        });
    }

    /**
     * Grade a student's work: marks (within the homework's maximum) and
     * feedback. Work done on paper can be graded without a submission.
     */
    public function grade(Homework $homework, StudentProfile $student, ?float $marks, ?string $feedback, User $by): HomeworkSubmission
    {
        throw_if($homework->isDraft(), ActionFailed::class, __('Publish the homework before grading it.'));
        throw_unless($this->roster($homework)->contains('id', $student->id) || $homework->submissions()->where('student_profile_id', $student->id)->exists(),
            ActionFailed::class, __('This student is not in the class the homework was set for.'));
        throw_if($marks !== null && $homework->max_marks !== null && $marks > (float) $homework->max_marks,
            ActionFailed::class, __('Marks can be at most :max.', ['max' => rtrim(rtrim((string) $homework->max_marks, '0'), '.')]));
        throw_if($marks === null && blank($feedback), ActionFailed::class, __('Give marks or feedback.'));

        $submission = DB::transaction(function () use ($homework, $student, $marks, $feedback, $by) {
            $submission = HomeworkSubmission::query()->lockForUpdate()->firstOrNew([
                'homework_id' => $homework->id,
                'student_profile_id' => $student->id,
            ]);

            $submission->fill([
                'status' => SubmissionStatus::Graded,
                'marks' => $marks,
                'feedback' => filled($feedback) ? trim($feedback) : null,
                'graded_by' => $by->id,
                'graded_at' => now(),
            ])->save();

            activity('homework_submission')->performedOn($submission)->causedBy($by)
                ->withProperties(['homework' => $homework->title, 'student' => $student->user?->name, 'marks' => $marks])
                ->log('Homework graded');

            return $submission;
        });

        $this->notifyStudent($submission, __('Homework graded: :title', ['title' => $homework->title]),
            $marks !== null && $homework->max_marks !== null
                ? __('You scored :marks out of :max.', ['marks' => self::number($marks), 'max' => self::number((float) $homework->max_marks)])
                : __('Your teacher left feedback on your work.'),
            'ri-checkbox-circle-line', 'success');

        return $submission;
    }

    /**
     * Send work back to the student to redo, with feedback.
     */
    public function sendBack(HomeworkSubmission $submission, string $feedback, User $by): void
    {
        $homework = $submission->homework;

        throw_unless($submission->isHandedIn() || $submission->isGraded(), ActionFailed::class, __('Only work that was handed in can be sent back.'));
        throw_unless($homework?->acceptsSubmissions(), ActionFailed::class, __('Reopen the homework first, so the student can hand it in again.'));

        $submission->update([
            'status' => SubmissionStatus::Returned,
            'marks' => null,
            'feedback' => trim($feedback),
            'graded_by' => $by->id,
            'graded_at' => now(),
        ]);

        activity('homework_submission')->performedOn($submission)->causedBy($by)
            ->withProperties(['homework' => $homework->title, 'student' => $submission->student?->user?->name])
            ->log('Homework sent back');

        $this->notifyStudent($submission, __('Homework sent back: :title', ['title' => $homework->title]),
            __('Your teacher asked you to look at it again.'), 'ri-arrow-go-back-line', 'warning');
    }

    public static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function notifyStudent(HomeworkSubmission $submission, string $title, string $message, string $icon, string $color): void
    {
        $user = $submission->student?->user;

        if ($user && $user->status === UserStatus::Active) {
            $user->notify(new SystemNotification($title, $message, SelfService::url('student', 'homework.show', $submission->student_profile_id, ['homework' => $submission->homework_id]), $icon, $color));
        }
    }

    private function store(UploadedFile $file, string $directory): string
    {
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'attachment';
        $extension = strtolower($file->getClientOriginalExtension());

        return $file->storeAs($directory, Str::limit($name, 80, '').'-'.Str::lower(Str::random(6)).'.'.$extension, Homework::DISK);
    }
}
