<?php

namespace App\Jobs;

use App\Enums\BatchStatus;
use App\Models\InvoiceBatch;
use App\Services\Fees\InvoiceBatches;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates a bulk invoice run. Queued; the school travels with the job.
 * Not retried automatically: a failed run is marked as such and can simply
 * be started again (students already billed are skipped).
 */
class GenerateInvoiceBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $batchId) {}

    public function handle(InvoiceBatches $batches): void
    {
        $batch = InvoiceBatch::query()->find($this->batchId);

        if ($batch && $batch->status === BatchStatus::Queued) {
            $batches->run($batch);
        }
    }
}
