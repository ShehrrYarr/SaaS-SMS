<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Printable fee invoices and receipts for the accounts office, as a web
 * page (with Print / Download PDF) or a PDF.
 */
class FeeDocumentController extends Controller
{
    public function invoice(int $invoice): View
    {
        Gate::authorize('invoices.view');

        return view('documents.invoice', [
            'invoice' => $this->findInvoice($invoice),
            'school' => tenant(),
            'printable' => true,
            'pdfUrl' => route('accounting.invoices.pdf', $invoice),
        ]);
    }

    public function invoicePdf(int $invoice): Response
    {
        Gate::authorize('invoices.view');
        $record = $this->findInvoice($invoice);

        return $this->pdf('documents.invoice', ['invoice' => $record])->download($record->invoice_number.'.pdf');
    }

    public function receipt(int $payment): View
    {
        Gate::authorize('fees.view');

        return view('documents.receipt', [
            'payment' => $this->findPayment($payment),
            'school' => tenant(),
            'printable' => true,
            'pdfUrl' => route('accounting.receipts.pdf', $payment),
        ]);
    }

    public function receiptPdf(int $payment): Response
    {
        Gate::authorize('fees.view');
        $record = $this->findPayment($payment);

        return $this->pdf('documents.receipt', ['payment' => $record])->download($record->receipt_number.'.pdf');
    }

    private function findInvoice(int $id): Invoice
    {
        return Invoice::query()
            ->with(['items', 'payments', 'student.user', 'student.schoolClass', 'student.section', 'student.parents.user'])
            ->findOrFail($id);
    }

    private function findPayment(int $id): Payment
    {
        return Payment::query()
            ->with(['receivedBy', 'invoice.items', 'invoice.student.user', 'invoice.student.schoolClass', 'invoice.student.section', 'invoice.student.parents.user'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pdf(string $view, array $data): PdfDocument
    {
        return Pdf::loadView($view, [...$data, 'school' => tenant()])
            ->setPaper('a4')
            // Embed only the glyphs used, so documents stay tens of KB.
            ->setOption('isFontSubsettingEnabled', true);
    }
}
