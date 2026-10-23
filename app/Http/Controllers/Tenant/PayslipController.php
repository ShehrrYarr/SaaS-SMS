<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\PayrollStatus;
use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Salary slips, for HR (payroll.view: approved or paid) and for the staff
 * member themself (paid only), as a web page or PDF.
 */
class PayslipController extends Controller
{
    public function show(Request $request, int $record): View
    {
        return view('documents.payslip', [
            'record' => $this->find($request, $record),
            'school' => tenant(),
            'printable' => true,
            'pdfUrl' => route('tenant.payslips.pdf', $record),
        ]);
    }

    public function pdf(Request $request, int $record): Response
    {
        $payroll = $this->find($request, $record);

        return Pdf::loadView('documents.payslip', ['record' => $payroll, 'school' => tenant()])
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)
            ->download(Str::slug('salary-slip '.$payroll->staff?->employee_id.' '.$payroll->month_year).'.pdf');
    }

    private function find(Request $request, int $id): PayrollRecord
    {
        $record = PayrollRecord::query()->with(['staff.user', 'staff.department'])->findOrFail($id);
        $user = $request->user();

        $allowed = ($user->can('payroll.view') && $record->status !== PayrollStatus::Draft)
            || ($record->isPaid() && $record->staff?->user_id === $user->id && $user->can('view-own-payslips'));

        abort_unless($allowed, 404);

        return $record;
    }
}
