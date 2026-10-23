<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\FinanceCategoryType;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The proof file (bill, receipt) attached to an expense or income entry,
 * from the private disk, for those who can see that ledger.
 */
class LedgerProofController extends Controller
{
    public function __invoke(string $type, int $entry): StreamedResponse
    {
        $kind = FinanceCategoryType::tryFrom($type) ?? abort(404);
        Gate::authorize($kind->permission('view'));

        $class = $kind->modelClass();
        $record = $class::query()->findOrFail($entry);

        abort_unless($record->proof_receipt && Storage::disk(Expense::DISK)->exists($record->proof_receipt), 404);

        return Storage::disk(Expense::DISK)->response($record->proof_receipt, null, [
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
