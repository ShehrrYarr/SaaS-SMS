<?php

namespace App\Tables;

use App\Enums\FinanceCategoryType;
use App\Models\FinanceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Income and expense categories. Managing a category needs the edit
 * permission of its ledger (income.edit / expenses.edit).
 */
class FinanceCategoriesTable extends Table
{
    public function authorize(): void
    {
        abort_unless(auth()->user()?->canAny(['income.view', 'expenses.view']), 403);
    }

    public function query(): Builder
    {
        $user = auth()->user();
        $types = collect(FinanceCategoryType::cases())->filter(fn (FinanceCategoryType $type) => $user?->can($type->permission('view')))->all();

        return FinanceCategory::query()
            ->whereIn('type', $types)
            ->withCount(['expenses', 'incomes']);
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Category'))->sortable()->searchable()
                ->format(fn (string $name, FinanceCategory $category) => new HtmlString(
                    '<span class="fw-medium text-body">'.e($name).'</span>'
                    .($category->isSystem() ? ' <span class="badge bg-info-subtle text-info">'.e(__('Automatic')).'</span>' : '')
                    .($category->description ? '<small class="d-block text-muted">'.e(str($category->description)->limit(70)).'</small>' : ''),
                )),
            Column::make('type', __('Ledger'))->sortable()->badge(),
            Column::make('entries', __('Entries'))->virtual()->alignEnd()
                ->format(fn ($value, FinanceCategory $category) => number_format($category->type === FinanceCategoryType::Income ? $category->incomes_count : $category->expenses_count)),
            Column::make('is_active', __('Active'))->boolean(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('type', __('Ledger'))->options(FinanceCategoryType::class),
        ];
    }

    public function actions(): array
    {
        $canEdit = fn (User $user, FinanceCategory $category) => $user->can($category->type->permission('edit'));

        return [
            Action::edit('forms.finance-category', 'category')->can($canEdit),

            Action::delete()
                ->can($canEdit)
                ->visible(fn (FinanceCategory $category) => ! $category->isSystem())
                ->handle(function (FinanceCategory $category) {
                    if ($category->isSystem()) {
                        throw new ActionFailed(__('This category is used for automatic entries, so it can\'t be deleted.'));
                    }

                    if ($category->expenses()->withTrashed()->exists() || $category->incomes()->withTrashed()->exists()) {
                        throw new ActionFailed(__(':name has entries, so it can\'t be deleted. Switch it off instead.', ['name' => $category->name]));
                    }

                    $category->delete();
                }),
        ];
    }

    public function emptyTitle(): string
    {
        return __('No categories');
    }
}
