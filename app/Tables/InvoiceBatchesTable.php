<?php

namespace App\Tables;

use App\Enums\BatchStatus;
use App\Models\InvoiceBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Bulk invoice runs, newest first, with live progress.
 */
class InvoiceBatchesTable extends Table
{
    public function permission(): ?string
    {
        return 'invoices.generate';
    }

    public function query(): Builder
    {
        return InvoiceBatch::query()->with('creator')->latest('id');
    }

    public function columns(): array
    {
        $school = tenant();

        return [
            Column::make('title', __('Run'))->searchable()
                ->format(fn (string $title, InvoiceBatch $batch) => new HtmlString(
                    '<span class="fw-medium text-body d-block">'.e($title).'</span>'
                    .'<small class="text-muted">'.e(collect([
                        $batch->creator?->name,
                        $school ? $school->localTime($batch->created_at)->format($school->setting('date_format').', '.$school->setting('time_format')) : null,
                    ])->filter()->implode(' · ')).'</small>',
                )),
            Column::make('progress', __('Progress'))->virtual()
                ->format(function ($value, InvoiceBatch $batch) {
                    $total = max($batch->total_students, 1);
                    $percent = $batch->status === BatchStatus::Completed ? 100 : (int) floor($batch->processed / $total * 100);

                    return new HtmlString(
                        '<div class="progress mb-1" style="height: 6px; min-width: 120px" role="progressbar" aria-valuenow="'.$percent.'" aria-valuemin="0" aria-valuemax="100">'
                        .'<div class="progress-bar bg-'.$batch->status->color().'" style="width: '.$percent.'%"></div></div>'
                        .'<small class="text-muted">'.e(__(':processed of :total students', ['processed' => $batch->processed, 'total' => $batch->total_students])).'</small>',
                    );
                }),
            Column::make('invoices_created', __('Invoices'))->alignEnd()
                ->format(fn ($value, InvoiceBatch $batch) => new HtmlString(
                    '<span class="fw-medium">'.number_format($batch->invoices_created).'</span>'
                    .($batch->skipped ? '<small class="d-block text-muted">'.e(trans_choice(':count skipped|:count skipped', $batch->skipped, ['count' => $batch->skipped])).'</small>' : ''),
                )),
            Column::make('status', __('Status'))
                ->format(fn (BatchStatus $status, InvoiceBatch $batch) => new HtmlString(
                    sprintf('<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', $status->color(), e($status->label()))
                    .($batch->error ? '<small class="d-block text-danger mt-1">'.e($batch->error).'</small>' : ''),
                )),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', __('Status'))->options(BatchStatus::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('invoices', __('See invoices'))
                ->icon('ri-file-list-3-line')
                ->url(fn (InvoiceBatch $batch) => route('accounting.invoice-batches.index', ['batch' => $batch->id]))
                ->visible(fn (InvoiceBatch $batch) => $batch->invoices_created > 0),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No bulk runs yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Generate a month\'s invoices for whole classes, or the whole school, in one go.');
    }
}
