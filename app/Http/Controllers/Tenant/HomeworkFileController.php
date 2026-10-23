<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Homework\Homeworks;
use App\Support\SelfService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of homework: the teacher's attachment, and what a student handed in.
 *
 *  - The homework's own file: staff who look after it, and (once published)
 *    the students it was set for and their parents.
 *  - A submission's file: staff who look after the homework, the student,
 *    and their parents.
 */
class HomeworkFileController extends Controller
{
    public function __construct(private readonly Homeworks $homeworks) {}

    public function attachment(int $homework): StreamedResponse
    {
        Gate::authorize('self-service-homework');

        $record = Homework::query()->with('subjectClass')->findOrFail($homework);
        $user = auth()->user();

        $allowed = $this->staffLooksAfter($user, $record)
            || (! $record->isDraft() && $this->familyStudents($user)->contains(fn (StudentProfile $student) => $this->homeworks->isFor($record, $student)));

        abort_unless($allowed && $record->attachment, 404);

        return $this->download($record->attachment);
    }

    public function submission(int $submission): StreamedResponse
    {
        Gate::authorize('self-service-homework');

        $record = HomeworkSubmission::query()->with('homework.subjectClass')->findOrFail($submission);
        $user = auth()->user();

        $allowed = ($record->homework && $this->staffLooksAfter($user, $record->homework))
            || SelfService::owns($user, $record->student_profile_id);

        abort_unless($allowed && $record->attachment, 404);

        return $this->download($record->attachment);
    }

    private function staffLooksAfter(User $user, Homework $homework): bool
    {
        return $user->canAccessPortal('teacher') && $user->can('homework.view') && $this->homeworks->looksAfter($user, $homework);
    }

    /**
     * The student themself, or a parent's children.
     *
     * @return Collection<int, StudentProfile>
     */
    private function familyStudents(User $user): Collection
    {
        $own = $user->studentProfile()->first();

        return SelfService::children($user)->when($own, fn ($students) => $students->push($own));
    }

    private function download(string $path): StreamedResponse
    {
        abort_unless(Storage::disk(Homework::DISK)->exists($path), 404);

        return Storage::disk(Homework::DISK)->download($path, basename($path));
    }
}
