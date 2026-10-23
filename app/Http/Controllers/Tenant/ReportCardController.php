<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Services\Exams\Gradebook;
use App\Services\Exams\Grading;
use App\Services\Exams\ResultSheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Report cards for school staff (examinations.view): one student as a
 * printable page or PDF, or a whole class/section as one PDF.
 */
class ReportCardController extends Controller
{
    public function __construct(
        private readonly ResultSheet $results,
        private readonly Grading $grading,
        private readonly Gradebook $gradebook,
    ) {}

    public function show(int $examination, int $student): View
    {
        [$exam, $card] = $this->card($examination, $student);

        return view('documents.report-card', [
            'cards' => [$card],
            'school' => tenant(),
            'scale' => $this->grading->scaleFor($exam),
            'printable' => true,
            'pdfUrl' => route('admin.examinations.report-card.pdf', ['examination' => $exam->id, 'student' => $student]),
        ]);
    }

    public function pdf(int $examination, int $student): Response
    {
        [$exam, $card] = $this->card($examination, $student);

        return $this->render([$card], $exam)
            ->download(Str::slug('report-card '.$card['student']->admission_number.' '.$exam->name).'.pdf');
    }

    /**
     * Every student of a class, or one section, in one PDF.
     */
    public function section(Request $request, int $examination): Response
    {
        $exam = $this->exam($examination);
        $class = SchoolClass::query()->with('sections')->findOrFail((int) $request->query('class'));
        $section = $request->filled('section') ? $class->sections->firstWhere('id', (int) $request->query('section')) : null;

        abort_if($request->filled('section') && ! $section, 404);

        $cards = $this->gradebook->roster($exam, $class, $section)
            ->map(fn ($enrollment) => $this->results->forStudent($exam, $enrollment->student))
            ->filter()
            ->values()
            ->all();

        abort_if($cards === [], 404);

        return $this->render($cards, $exam)
            ->stream(Str::slug('report-cards '.$exam->name.' '.$class->name.' '.($section?->name ?? '')).'.pdf');
    }

    /**
     * @return array{0: Examination, 1: array<string, mixed>}
     */
    private function card(int $examination, int $student): array
    {
        $exam = $this->exam($examination);
        $card = $this->results->forStudent($exam, StudentProfile::query()->with('user')->findOrFail($student));

        abort_unless($card, 404);

        return [$exam, $card];
    }

    private function exam(int $examination): Examination
    {
        return Examination::query()->with(['academicYear', 'term'])->findOrFail($examination);
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    private function render(array $cards, Examination $exam): PdfDocument
    {
        return Pdf::loadView('documents.report-card', [
            'cards' => $cards,
            'school' => tenant(),
            'scale' => $this->grading->scaleFor($exam),
        ])
            ->setPaper('a4')
            // Embed only the glyphs used, so a report card is tens of KB rather than most of a font file.
            ->setOption('isFontSubsettingEnabled', true);
    }
}
