<?php

namespace App\Tables;

use App\Enums\FinanceCategoryType;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\IncomeLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * The income or expense ledger: ['type' => 'income' | 'expense'].
 *
 * Entries posted automatically (fee receipts, paid salaries) are read-only
 * here; they change with the receipt or payroll they came from.
 */
class LedgerEntriesTable extends Table
{
    private function type(): FinanceCategoryType
    {
        return FinanceCategoryType::tryFrom((string) ($this->params['type'] ?? '')) ?? FinanceCategoryType::Expense;
    }

    public function permission(): ?string
    {
        return $this->type()->permission('view');
    }

    public function query(): Builder
    {
        $class = $this->type()->modelClass();

        return $class::query()->with(['category' => fn ($query) => $query->withTrashed(), 'recorder']);
    }

    public function defaultSort(): array
    {
        return [$this->type()->dateColumn(), 'desc'];
    }

    public function columns(): array
    {
        $type = $this->type();
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return [
            Column::make($type->dateColumn(), __('Date'))->sortable()->date(),
            Column::make('title', __('Description'))->sortable()->searchable()
                ->format(fn (string $title, Expense|IncomeLedger $entry) => new HtmlString(
                    '<span class="d-block text-body">'.e($title).'</span>'
                    .'<small class="text-muted">'.e(collect([
                        $entry->category?->name,
                        $entry->reference,
                        $entry->isAutomatic() ? __('posted automatically') : null,
                    ])->filter()->implode(' · ')).'</small>',
                )),
            Column::make('payment_method', __('Method'))->hiddenOnMobile()
                ->format(fn (?PaymentMethod $method) => $method?->label()),
            Column::make('proof_receipt', __('Proof'))->hiddenOnMobile()
                ->format(fn (?string $path, Expense|IncomeLedger $entry) => $path ? new HtmlString(
                    '<a href="'.e(route('accounting.ledger.proof', ['type' => $type->value, 'entry' => $entry->id])).'" target="_blank" rel="noopener"><i class="ri-attachment-2"></i> '.e(__('Open')).'</a>',
                ) : null),
            Column::make('recorded_by', __('Recorded by'))->hiddenOnMobile()
                ->format(fn ($value, Expense|IncomeLedger $entry) => $entry->recorder?->name),
            Column::make('amount', __('Amount'))->sortable()->money(),
        ];
    }

    public function filters(): array
    {
        $type = $this->type();
        $months = collect(range(0, 11))->mapWithKeys(function (int $back) {
            $month = (tenant()?->today() ?? Carbon::today())->copy()->startOfMonth()->subMonths($back);

            return [$month->format('Y-m') => $month->translatedFormat('F Y')];
        });

        return [
            Filter::make('month', __('Month'))
                ->options($months->all())
                ->query(fn (Builder $query, string $value) => $query
                    ->whereDate($type->dateColumn(), '>=', Carbon::createFromFormat('!Y-m', $value)->startOfMonth())
                    ->whereDate($type->dateColumn(), '<=', Carbon::createFromFormat('!Y-m', $value)->endOfMonth())),
            Filter::make('finance_category_id', __('Category'))
                ->options(FinanceCategory::query()->where('type', $type)->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
            Filter::make('payment_method', __('Method'))->options(PaymentMethod::class),
        ];
    }

    public function actions(): array
    {
        $type = $this->type();
        $manual = fn (Model $entry) => ! $entry->isAutomatic();

        return [
            Action::make('edit', __('Edit'))
                ->icon('ri-pencil-line')
                ->can($type->permission('edit'))
                ->visible($manual)
                ->modal('forms.ledger-entry', fn (Model $entry) => ['type' => $type->value, 'entry' => $entry->getKey()]),

            Action::delete($type->permission('delete'))
                ->visible($manual)
                ->handle(function (Model $entry) {
                    if ($entry->isAutomatic()) {
                        throw new ActionFailed(__('This entry was posted automatically; it changes with the receipt or payroll it came from.'));
                    }

                    $entry->delete();
                }),
        ];
    }

    public function searchPlaceholder(): string
    {
        return __('Search by description…');
    }

    public function emptyTitle(): string
    {
        return $this->type() === FinanceCategoryType::Income ? __('No income recorded') : __('No expenses recorded');
    }

    public function emptyMessage(): ?string
    {
        return $this->type() === FinanceCategoryType::Income
            ? __('Fee receipts are added here automatically. Record other income, such as donations, yourself.')
            : __('Paid salaries are added here automatically. Record bills and purchases yourself.');
    }
}
