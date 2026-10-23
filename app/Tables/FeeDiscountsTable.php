<?php

namespace App\Tables;

use App\Enums\AdjustmentType;
use App\Models\FeeDiscount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Discount and scholarship definitions, assigned to students separately.
 */
class FeeDiscountsTable extends Table
{
    public function permission(): ?string
    {
        return 'fee-discounts.view';
    }

    public function query(): Builder
    {
        return FeeDiscount::query()->with('feeStructure')->withCount('assignments');
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Discount'))->sortable()->searchable()
                ->format(fn (string $name, FeeDiscount $discount) => new HtmlString(
                    '<span class="fw-medium text-body d-block">'.e($name).($discount->code ? ' <small class="text-muted">('.e($discount->code).')</small>' : '').'</span>'
                    .($discount->description ? '<small class="text-muted">'.e(str($discount->description)->limit(70)).'</small>' : ''),
                )),
            Column::make('value', __('Value'))->sortable()
                ->format(fn ($value, FeeDiscount $discount) => $discount->valueLabel()),
            Column::make('fee_structure_id', __('Applies to'))
                ->format(fn ($value, FeeDiscount $discount) => $discount->feeStructure?->title ?? __('Any fee')),
            Column::make('assignments_count', __('Students'))->sortable(),
            Column::make('is_active', __('Active'))->boolean(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('type', __('Type'))->options(AdjustmentType::class),
            Filter::make('is_active', __('Status'))->options(['1' => __('Active'), '0' => __('Inactive')]),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.fee-discount', 'feeDiscount', 'fee-discounts.manage'),

            Action::delete('fee-discounts.manage', __('Invoices already raised keep the reduction.'))
                ->handle(function (FeeDiscount $discount) {
                    if ($discount->assignments()->exists()) {
                        throw new ActionFailed(__(':name is given to students. Remove it from them first, or switch it off.', ['name' => $discount->name]));
                    }

                    $discount->delete();
                }),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No discounts yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Create discounts and scholarships, then give them to students.');
    }
}
