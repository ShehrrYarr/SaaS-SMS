<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Examination;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Services\Exams\Grading;
use App\Services\Exams\ResultSheet;
use App\Support\Portal;
use App\Support\SelfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Fee invoices, receipts and report cards in the student and parent portals:
 * only for the signed-in student, or one of the parent's children. Anything
 * else is a 404, so other students' records can't be probed.
 */
class FamilyDocumentController extends Controller
{
    public function invoice(int $invoice): View
    {
        $record = $this->findInvoice($invoice);

        return view('documents.invoice', [
            'invoice' => $record,
            'school' => tenant(),
            'printable' => true,
            'pdfUrl' => route(Portal::current().'.invoices.pdf', $record->id),
        ]);
    }

    public function invoicePdf(int $invoice): Response
    {
        $record = $this->findInvoice($invoice);

        return $this->pdf('documents.invoice', ['invoice' => $record])->download($record->invoice_number.'.pdf');
    }

    public function receipt(int $payment): View
    {
        $record = $this->findPayment($payment);

        return view('documents.receipt', [
            'payment' => $record,
            'school' => tenant(),
            'printable' => true,
            'pdfUrl' => route(Portal::current().'.receipts.pdf', $record->id),
        ]);
    }

    public function receiptPdf(int $payment): Response
    {
        $record = $this->findPayment($payment);

        return $this->pdf('documents.receipt', ['payment' => $record])->download($record->receipt_number.'.pdf');
    }

    public function reportCard(ResultSheet $results, Grading $grading, int $examination, int $student): View
    {
        [$exam, $card] = $this->card($results, $examination, $student);

        return view('documents.report-card', [
            'cards' => [$card],
            'school' => tenant(),
            'scale' => $grading->scaleFor($exam),
            'printable' => true,
            'pdfUrl' => route(Portal::current().'.report-card.pdf', ['examination' => $exam->id, 'student' => $student]),
        ]);
    }

    public function reportCardPdf(ResultSheet $results, Grading $grading, int $examination, int $student): Response
    {
        [$exam, $card] = $this->card($results, $examination, $student);

        return Pdf::loadView('documents.report-card', ['cards' => [$card], 'school' => tenant(), 'scale' => $grading->scaleFor($exam)])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)
            ->download(Str::slug('report-card '.$card['student']->admission_number.' '.$exam->name).'.pdf');
    }

    /**
     * @return array{0: Examination, 1: array<string, mixed>}
     */
    private function card(ResultSheet $results, int $examination, int $student): array
    {
        Gate::authorize('self-service-results');
        $this->ensureOwns($student);

        $exam = Examination::query()->with(['academicYear', 'term'])->findOrFail($examination);
        abort_unless($exam->isPublished(), 404);

        $card = $results->forStudent($exam, StudentProfile::query()->with('user')->findOrFail($student));
        abort_unless($card, 404);

        return [$exam, $card];
    }

    private function findInvoice(int $id): Invoice
    {
        Gate::authorize('self-service-fees');

        $invoice = Invoice::query()
            ->with(['items', 'payments', 'student.user', 'student.schoolClass', 'student.section', 'student.parents.user'])
            ->findOrFail($id);

        $this->ensureOwns($invoice->student_profile_id);
        abort_if($invoice->isCancelled(), 404);

        return $invoice;
    }

    private function findPayment(int $id): Payment
    {
        Gate::authorize('self-service-fees');

        $payment = Payment::query()
            ->with(['receivedBy', 'invoice.items', 'invoice.student.user', 'invoice.student.schoolClass', 'invoice.student.section', 'invoice.student.parents.user'])
            ->findOrFail($id);

        $this->ensureOwns($payment->invoice?->student_profile_id ?? 0);
        abort_if($payment->isVoided(), 404);

        return $payment;
    }

    private function ensureOwns(int $studentId): void
    {
        abort_unless(SelfService::owns(auth()->user(), $studentId), 404);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pdf(string $view, array $data): PdfDocument
    {
        return Pdf::loadView($view, [...$data, 'school' => tenant()])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true);
    }
}
