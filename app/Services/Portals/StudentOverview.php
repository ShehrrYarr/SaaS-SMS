<?php

namespace App\Services\Portals;

use App\Enums\AttendanceStatus;
use App\Enums\BookIssueStatus;
use App\Enums\ExamStatus;
use App\Enums\HomeworkStatus;
use App\Enums\SubmissionStatus;
use App\Models\AcademicYear;
use App\Models\BookIssue;
use App\Models\Examination;
use App\Models\ExamRemark;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\StudentEnrollment;
use App\Models\StudentHostel;
use App\Models\StudentProfile;
use App\Models\StudentTransport;
use App\Models\Timetable;
use App\Models\User;
use App\Services\Exams\ResultSheet;
use App\Services\Timetable\Timetables;
use App\Support\Billing\PlanFeatures;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One student's school life as the student and parent portals show it:
 * attendance, today's timetable, homework, fees, results and teacher remarks.
 */
class StudentOverview
{
    public function __construct(
        private readonly Timetables $timetables,
        private readonly ResultSheet $results,
    ) {}

    public function year(): ?AcademicYear
    {
        return AcademicYear::query()->current()->first();
    }

    /**
     * Whether the school's plan includes a feature the portals show
     * (homework, examinations, finance).
     */
    public static function enabled(string $permission): bool
    {
        return tenant() !== null && PlanFeatures::allows(tenant()->plan, $permission);
    }

    /**
     * Days per status between two dates (default: the current academic year
     * so far) and the attendance rate.
     *
     * present counts late days too; school days leave out days on leave
     * (excused), which don't count against the rate.
     *
     * @return array{counts: Collection<string, int>, marked: int, rate: float|null, present: int, excused: int, school_days: int}
     */
    public function attendance(StudentProfile $student, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        if (! $from && ! $to && ($year = $this->year())) {
            $from = $year->start_date;
        }

        $counts = StudentAttendance::query()
            ->where('student_profile_id', $student->id)
            ->when($from, fn (Builder $query) => $query->whereDate('date', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('date', '<=', $to))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->toBase()
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $excused = $counts->get(AttendanceStatus::Excused->value, 0);

        return [
            'counts' => $counts,
            'marked' => (int) $counts->sum(),
            'rate' => AttendanceStatus::rate($counts),
            'present' => $counts->get(AttendanceStatus::Present->value, 0) + $counts->get(AttendanceStatus::Late->value, 0),
            'excused' => $excused,
            'school_days' => (int) $counts->sum() - $excused,
        ];
    }

    /**
     * A month's attendance, keyed by date (Y-m-d).
     *
     * @return Collection<string, StudentAttendance>
     */
    public function attendanceMonth(StudentProfile $student, CarbonInterface $month): Collection
    {
        return StudentAttendance::query()
            ->where('student_profile_id', $student->id)
            ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->get()
            ->keyBy(fn (StudentAttendance $record) => $record->date->toDateString());
    }

    /**
     * Today's periods, in time order.
     *
     * @return Collection<int, Timetable>
     */
    public function today(StudentProfile $student): Collection
    {
        return Timetables::day($this->timetables->forStudent($student), tenant()->today()->isoWeekday());
    }

    /**
     * Homework set for the student (published or closed), each with their
     * own submission loaded.
     *
     * @return Builder<Homework>
     */
    public function homework(StudentProfile $student): Builder
    {
        return Homework::query()
            ->forStudent($student)
            ->with([
                'subjectClass.subject', 'subjectClass.teacher.user',
                'submissions' => fn ($query) => $query->where('student_profile_id', $student->id),
            ]);
    }

    /**
     * Open homework the student still has to hand in (never handed in, or
     * sent back), soonest due first.
     *
     * @return Collection<int, Homework>
     */
    public function upcomingHomework(StudentProfile $student, int $limit = 5): Collection
    {
        return $this->toDo($student)->orderBy('due_at')->limit($limit)->get();
    }

    public function homeworkToDoCount(StudentProfile $student): int
    {
        return $this->toDo($student)->count();
    }

    /**
     * @return array{outstanding: float, overdue: float, open: int, next: Invoice|null, paid_this_year: float}
     */
    public function fees(StudentProfile $student): array
    {
        $open = Invoice::query()->where('student_profile_id', $student->id)->open()->orderBy('due_date')->get();
        $today = tenant()->today();
        $year = $this->year();

        return [
            'outstanding' => round($open->sum(fn (Invoice $invoice) => $invoice->balanceAmount()), 2),
            'overdue' => round($open->filter(fn (Invoice $invoice) => $invoice->due_date->lt($today))->sum(fn (Invoice $invoice) => $invoice->balanceAmount()), 2),
            'open' => $open->count(),
            'next' => $open->first(),
            'paid_this_year' => round((float) Payment::query()->valid()
                ->whereHas('invoice', fn (Builder $query) => $query->where('student_profile_id', $student->id))
                ->when($year, fn (Builder $query) => $query->whereDate('payment_date', '>=', $year->start_date))
                ->sum('amount_paid'), 2),
        ];
    }

    /**
     * Published examinations the student sat (their class in that year had
     * papers), newest first.
     *
     * @return Collection<int, Examination>
     */
    public function publishedExams(StudentProfile $student): Collection
    {
        $enrollments = StudentEnrollment::query()->where('student_profile_id', $student->id)->get(['academic_year_id', 'class_id']);

        if ($enrollments->isEmpty()) {
            return collect();
        }

        return Examination::query()
            ->with(['academicYear', 'term'])
            ->where('status', ExamStatus::Published)
            ->where(function (Builder $query) use ($enrollments) {
                foreach ($enrollments as $enrollment) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('academic_year_id', $enrollment->academic_year_id)
                        ->whereHas('schedules', fn (Builder $schedule) => $schedule->where('class_id', $enrollment->class_id)));
                }
            })
            ->orderByDesc('results_published_at')
            ->orderByDesc('end_date')
            ->get();
    }

    /**
     * The report card of the newest published examination.
     *
     * @return array<string, mixed>|null
     */
    public function latestResult(StudentProfile $student): ?array
    {
        $exam = $this->publishedExams($student)->first();

        return $exam ? $this->results->forStudent($exam, $student) : null;
    }

    /**
     * What teachers have written lately: report-card remarks of published
     * examinations and feedback on homework.
     *
     * @return Collection<int, array{type: string, title: string, text: string, by: string|null, at: CarbonInterface|null}>
     */
    public function remarks(StudentProfile $student, int $limit = 5): Collection
    {
        $exam = ExamRemark::query()
            ->with(['examination', 'enteredBy'])
            ->where('student_profile_id', $student->id)
            ->whereHas('examination', fn (Builder $query) => $query->where('status', ExamStatus::Published))
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (ExamRemark $remark) => [
                'type' => 'exam',
                'title' => $remark->examination?->name,
                'text' => $remark->remarks,
                'by' => $remark->enteredBy?->name,
                'at' => $remark->examination?->results_published_at ?? $remark->updated_at,
            ]);

        $homework = HomeworkSubmission::query()
            ->with(['homework.subjectClass.subject', 'gradedBy'])
            ->where('student_profile_id', $student->id)
            ->whereNotNull('feedback')
            ->whereIn('status', [SubmissionStatus::Graded, SubmissionStatus::Returned])
            ->whereHas('homework')
            ->latest('graded_at')
            ->limit($limit)
            ->get()
            ->map(fn (HomeworkSubmission $submission) => [
                'type' => 'homework',
                'title' => $submission->homework?->subjectClass?->subject?->name.' · '.$submission->homework?->title,
                'text' => $submission->feedback,
                'by' => $submission->gradedBy?->name,
                'at' => $submission->graded_at,
            ]);

        return $exam->concat($homework)->sortByDesc(fn (array $remark) => $remark['at']?->timestamp ?? 0)->take($limit)->values();
    }

    /**
     * Library loans of a borrower (a student's account, or a member of
     * staff): books out now, earlier loans and fines owed.
     *
     * @return array{out: Collection<int, BookIssue>, overdue: int, history: Collection<int, BookIssue>, owed: float}
     */
    public function library(?User $borrower, int $history = 20): array
    {
        if (! $borrower) {
            return ['out' => collect(), 'overdue' => 0, 'history' => collect(), 'owed' => 0.0];
        }

        $book = ['book' => fn ($query) => $query->withTrashed()];
        $out = BookIssue::query()->out()->with($book)->where('user_id', $borrower->id)->orderBy('due_on')->get();

        return [
            'out' => $out,
            'overdue' => $out->filter(fn (BookIssue $issue) => $issue->isOverdue())->count(),
            'history' => BookIssue::query()->with([...$book, 'invoice'])->where('user_id', $borrower->id)
                ->where('status', '!=', BookIssueStatus::Issued)
                ->latest('returned_on')->latest('id')->limit($history)->get(),
            'owed' => (float) BookIssue::query()->fineOwed()->where('user_id', $borrower->id)->sum('fine_amount'),
        ];
    }

    /**
     * The route the student rides now, with its vehicle.
     */
    public function transport(StudentProfile $student): ?StudentTransport
    {
        return StudentTransport::query()->active()
            ->with(['route' => fn ($query) => $query->withTrashed()->with('vehicle')])
            ->where('student_profile_id', $student->id)
            ->first();
    }

    /**
     * The hostel room the student stays in now, with its building and warden.
     */
    public function hostel(StudentProfile $student): ?StudentHostel
    {
        return StudentHostel::query()->active()
            ->with(['room' => fn ($query) => $query->withTrashed()->with(['building' => fn ($query) => $query->withTrashed()->with('warden.user')])])
            ->where('student_profile_id', $student->id)
            ->first();
    }

    /**
     * @return Builder<Homework>
     */
    private function toDo(StudentProfile $student): Builder
    {
        return Homework::query()
            ->forStudent($student)
            ->where('status', HomeworkStatus::Published)
            ->with(['subjectClass.subject', 'submissions' => fn ($query) => $query->where('student_profile_id', $student->id)])
            ->whereDoesntHave('submissions', fn (Builder $query) => $query
                ->where('student_profile_id', $student->id)
                ->where('status', '!=', SubmissionStatus::Returned));
    }
}
