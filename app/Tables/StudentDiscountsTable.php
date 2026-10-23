<?php

namespace App\Tables;

use App\Models\FeeDiscount;
use App\Models\StudentFeeDiscount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Which students get which discount, and for how long.
 */
class StudentDiscountsTable extends Table
{
    public function permission(): ?string
    {
        return 'fee-discounts.view';
    }

    public function query(): Builder
    {
        return StudentFeeDiscount::query()
            ->with(['discount' => fn ($query) => $query->withTrashed(), 'student.user', 'student.schoolClass', 'student.section', 'approvedBy'])
            ->whereHas('student');
    }

    public function defaultSort(): array
    {
        return ['valid_from', 'desc'];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return [
            Column::make('student', __('Student'))->virtual()->searchable()
                ->format(fn ($value, StudentFeeDiscount $row) => Cells::person($row->student?->user, collect([$row->student?->admission_number, $row->student?->placementLabel()])->filter()->implode(' · '))),
            Column::make('fee_discount_id', __('Discount'))
                ->format(fn ($value, StudentFeeDiscount $row) => new HtmlString(
                    '<span class="d-block">'.e($row->discount?->name).'</span><small class="text-muted">'.e($row->discount?->valueLabel()).'</small>',
                )),
            Column::make('valid_from', __('Valid'))->sortable()
                ->format(fn ($value, StudentFeeDiscount $row) => match (true) {
                    $row->valid_from && $row->valid_until => $row->valid_from->format($format).' – '.$row->valid_until->format($format),
                    $row->valid_from !== null => __('From :date', ['date' => $row->valid_from->format($format)]),
                    $row->valid_until !== null => __('Until :date', ['date' => $row->valid_until->format($format)]),
                    default => __('Always'),
                }),
            Column::make('remarks', __('Remarks'))->hiddenOnMobile()
                ->format(fn (?string $remarks, StudentFeeDiscount $row) => new HtmlString(
                    e((string) $remarks).($row->approvedBy ? '<small class="d-block text-muted">'.e(__('By :name', ['name' => $row->approvedBy->name])).'</small>' : ''),
                )),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('fee_discount_id', __('Discount'))
                ->options(FeeDiscount::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
        ];
    }

    public function actions(): array
    {
        return [
            Action::edit('forms.student-discount', 'assignment', 'fee-discounts.manage'),

            Action::make('remove', __('Remove'))
                ->icon('ri-close-circle-line')
                ->color('danger')
                ->can('fee-discounts.manage')
                ->confirm(__('Invoices already raised keep the reduction; new ones won\'t include it.'), __('Remove this discount from the student?'))
                ->handle(function (StudentFeeDiscount $row) {
                    activity('student_fee_discount')->performedOn($row)->causedBy(auth()->user())
                        ->withProperties(['student' => $row->student?->user?->name, 'discount' => $row->discount?->name])
                        ->log('Discount removed from student');

                    $row->delete();
                })
                ->success(__('Discount removed.')),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->whereHas('student', fn (Builder $student) => $student
            ->where('admission_number', 'like', $term)
            ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by student…');
    }

    public function emptyTitle(): string
    {
        return __('No students have a discount');
    }
}
