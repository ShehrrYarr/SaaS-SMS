<?php

namespace App\Tables;

use App\Enums\AdjustmentType;
use App\Models\SiblingDiscountRule;
use App\Support\Billing\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Second child 10% off, third and later 15%", for any fee or one fee.
 */
class SiblingDiscountRulesTable extends Table
{
    public function permission(): ?string
    {
        return 'fee-discounts.view';
    }

    public function query(): Builder
    {
        return SiblingDiscountRule::query()->with('feeStructure');
    }

    public function defaultSort(): array
    {
        return ['child_position', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('child_position', __('From child'))->sortable()
                ->format(fn (int $position) => __('Child :position onwards', ['position' => $position])),
            Column::make('value', __('Discount'))
                ->format(fn ($value, SiblingDiscountRule $rule) => $rule->type === AdjustmentType::Percentage
                    ? Money::input($rule->value).'%'
                    : Money::school($rule->value)),
            Column::make('fee_structure_id', __('Applies to'))
                ->format(fn ($value, SiblingDiscountRule $rule) => $rule->feeStructure?->title ?? __('Any fee')),
            Column::make('is_active', __('Active'))->boolean(),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.sibling-rule', 'rule', 'fee-discounts.manage'),
            Action::delete('fee-discounts.manage', __('Invoices already raised keep the reduction.')),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No sibling discount rules');
    }

    public function emptyMessage(): ?string
    {
        return __('Add a rule such as “2nd child onwards: 10% off”.');
    }
}
