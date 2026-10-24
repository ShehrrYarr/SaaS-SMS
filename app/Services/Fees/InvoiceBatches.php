<?php

namespace App\Services\Fees;

use App\Enums\BatchStatus;
use App\Jobs\GenerateInvoiceBatch;
use App\Models\FeeStructure;
use App\Models\InvoiceBatch;
use App\Models\StudentProfile;
use App\Models\User;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk invoice generation: one billing period, the chosen fees (and service
 * charges: transport fares, hostel fees), every active student of the chosen
 * classes (all classes when none are chosen). Runs on the queue and records
 * its progress on the batch for the UI.
 *
 * Students already billed for a fee or service in that period are skipped
 * for it, so a batch can safely be run again for students admitted later.
 */
class InvoiceBatches
{
    private const CHUNK = 100;

    public function __construct(private readonly Invoices $invoices, private readonly FeeSchedule $schedule, private readonly ServiceCharges $services) {}

    /**
     * @param  array{title: string, billing_period: string, class_ids: list<int>, fee_structure_ids: list<int>, services?: list<string>, issue_date: string, due_date: string}  $data
     *
     * @throws ActionFailed
     */
    public function start(array $data, User $by): InvoiceBatch
    {
        if (! tenant()->setting('fees.bulk_generation_enabled')) {
            throw new ActionFailed(__('Bulk invoice generation is switched off in School settings → Fee options.'));
        }

        $running = InvoiceBatch::query()
            ->where('billing_period', $data['billing_period'])
            ->whereIn('status', [BatchStatus::Queued, BatchStatus::Processing])
            ->exists();

        if ($running) {
            throw new ActionFailed(__('Invoices for :period are already being generated. Wait for that run to finish.', ['period' => FeeSchedule::periodLabel($data['billing_period'])]));
        }

        return DB::transaction(function () use ($data, $by) {
            $batch = InvoiceBatch::query()->create([
                'academic_year_id' => $this->schedule->yearFor($data['billing_period'])?->id,
                'title' => trim($data['title']),
                'billing_period' => $data['billing_period'],
                'filters' => [
                    'class_ids' => array_values(array_map('intval', $data['class_ids'])),
                    'fee_structure_ids' => array_values(array_map('intval', $data['fee_structure_ids'])),
                    'services' => array_values(array_intersect($data['services'] ?? [], ServiceCharges::available())),
                    'issue_date' => $data['issue_date'],
                    'due_date' => $data['due_date'],
                ],
                'status' => BatchStatus::Queued,
                'total_students' => $this->students($data['class_ids'])->count(),
                'created_by' => $by->id,
            ]);

            activity('invoice_batch')->performedOn($batch)->causedBy($by)
                ->withProperties(['period' => $batch->billing_period, 'students' => $batch->total_students])
                ->log('Bulk invoice generation started');

            GenerateInvoiceBatch::dispatch($batch->id)->afterCommit();

            return $batch;
        });
    }

    /**
     * Active students of the classes (every class when empty).
     *
     * @param  list<int|string>  $classIds
     * @return Builder<StudentProfile>
     */
    public function students(array $classIds): Builder
    {
        return StudentProfile::query()
            ->active()
            ->whereNotNull('class_id')
            ->when($classIds !== [], fn ($query) => $query->whereIn('class_id', $classIds));
    }

    /**
     * Generate the batch's invoices (called by the queued job).
     */
    public function run(InvoiceBatch $batch): void
    {
        $batch->update(['status' => BatchStatus::Processing, 'started_at' => now(), 'processed' => 0, 'invoices_created' => 0, 'skipped' => 0, 'error' => null]);

        try {
            $filters = $batch->filters;
            $fees = FeeStructure::query()->whereIn('id', $filters['fee_structure_ids'] ?? [])->get();
            $issue = Carbon::parse($filters['issue_date']);
            $due = Carbon::parse($filters['due_date']);
            $periodStart = FeeSchedule::periodStart($batch->billing_period);
            $by = User::query()->find($batch->created_by) ?? throw new ActionFailed(__('The person who started this run no longer has an account.'));

            $students = $this->students($filters['class_ids'] ?? []);
            $batch->update(['total_students' => (clone $students)->count()]);

            $services = $filters['services'] ?? [];

            $students->with('user')->chunkById(self::CHUNK, function ($chunk) use ($batch, $fees, $services, $issue, $due, $periodStart, $by) {
                foreach ($chunk as $student) {
                    $lines = [
                        ...$this->invoices->linesFor($student, $fees, $batch->billing_period, $periodStart),
                        ...$this->services->linesFor($student, $batch->billing_period, $services),
                    ];

                    try {
                        if ($lines === []) {
                            $batch->skipped++;
                        } else {
                            $this->invoices->create($student, $lines, $issue, $due, $batch->billing_period, $by, batch: $batch);
                            $batch->invoices_created++;
                        }
                    } catch (ActionFailed) {
                        $batch->skipped++;
                    }

                    $batch->processed++;
                }

                $batch->save();
            });

            $batch->update(['status' => BatchStatus::Completed, 'completed_at' => now()]);

            activity('invoice_batch')->performedOn($batch)->causedBy($by)
                ->withProperties(['created' => $batch->invoices_created, 'skipped' => $batch->skipped])
                ->log('Bulk invoice generation finished');
        } catch (Throwable $e) {
            $batch->update([
                'status' => BatchStatus::Failed,
                'completed_at' => now(),
                'error' => $e instanceof ActionFailed ? $e->getMessage() : __('Something went wrong while generating invoices. Invoices already created are kept; run it again to finish.'),
            ]);

            report($e);
        }
    }
}
