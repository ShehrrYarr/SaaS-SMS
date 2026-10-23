<?php

namespace App\Tables;

use App\Enums\FeeFrequency;
use App\Models\FeeStructure;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Fee heads: what is charged, to whom, how often.
 */
class FeeStructuresTable extends Table
{
    public function permission(): ?string
    {
        return 'fee-structures.view';
    }

    public function query(): Builder
    {
        return FeeStructure::query()->with(['schoolClass', 'academicYear']);
    }

    public function defaultSort(): array
    {
        return ['title', 'asc'];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return [
            Column::make('title', __('Fee'))->sortable()->searchable()
                ->format(fn (string $title, FeeStructure $fee) => new HtmlString(
                    '<span class="fw-medium text-body d-block">'.e($title).'</span>'
                    .'<small class="text-muted">'.e(collect([$fee->schoolClass?->name ?? __('All classes'), $fee->academicYear?->name ?? __('Every year')])->implode(' · ')).'</small>',
                )),
            Column::make('amount', __('Amount'))->sortable()->money(),
            Column::make('frequency', __('Charged'))->sortable()
                ->format(fn (FeeFrequency $frequency, FeeStructure $fee) => new HtmlString(
                    e($frequency->label())
                    .'<small class="d-block text-muted">'.e(match (true) {
                        $frequency === FeeFrequency::OneTime && $fee->due_date !== null => __('Due :date', ['date' => $fee->due_date->format($format)]),
                        $frequency !== FeeFrequency::OneTime && $fee->due_day !== null => __('Due on day :day', ['day' => $fee->due_day]),
                        default => '',
                    }).'</small>',
                )),
            Column::make('rules', __('Rules'))->virtual()->hiddenOnMobile()
                ->format(fn ($value, FeeStructure $fee) => Cells::badges(array_filter([
                    $fee->allow_discounts ? __('Discounts') : null,
                    $fee->allow_sibling_discount ? __('Sibling discount') : null,
                    match (true) {
                        $fee->late_fee_type === null => null,
                        (float) $fee->late_fee_amount <= 0 => __('No late fee'),
                        default => __('Own late fee'),
                    },
                ]), 'secondary')),
            Column::make('is_active', __('Active'))->boolean(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('class_id', __('Class'))
                ->options(['all' => __('All classes')] + SchoolClass::query()->ordered()->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all())
                ->query(fn (Builder $query, string $value) => $value === 'all' ? $query->whereNull('class_id') : $query->where('class_id', (int) $value)),
            Filter::make('frequency', __('Charged'))->options(FeeFrequency::class),
            Filter::make('is_active', __('Status'))
                ->options(['1' => __('Active'), '0' => __('Inactive')]),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('edit', __('Edit'))
                ->icon('ri-pencil-line')
                ->modal('forms.fee-structure', fn (FeeStructure $fee) => ['feeStructure' => $fee->id], 'lg')
                ->can('fee-structures.edit'),

            Action::delete('fee-structures.delete', __('Fees that have been billed can\'t be deleted; switch them off instead.'))
                ->handle(function (FeeStructure $fee) {
                    if ($fee->invoiceItems()->exists()) {
                        throw new ActionFailed(__(':name has been billed, so it can\'t be deleted. Switch it off instead.', ['name' => $fee->title]));
                    }

                    $fee->delete();
                }),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No fees set up yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Add the fees your school charges, such as tuition, admission or transport.');
    }
}
