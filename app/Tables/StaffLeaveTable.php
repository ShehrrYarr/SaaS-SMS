<?php

namespace App\Tables;

use App\Enums\LeaveAppliesTo;
use App\Enums\LeaveStatus;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Leave\StaffLeave;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Staff leave requests. Two views of the same list:
 *  - ['scope' => 'hr'] (HR portal): everyone's, to approve or reject;
 *  - ['scope' => 'self'] (My leave): the signed-in staff member's own.
 */
class StaffLeaveTable extends Table
{
    private function self(): bool
    {
        return ($this->params['scope'] ?? null) === 'self';
    }

    public function permission(): ?string
    {
        return $this->self() ? 'leave.apply' : 'leave.view';
    }

    public function query(): Builder
    {
        $query = LeaveRequest::query()
            ->with([
                'leaveType',
                'requestedBy',
                'reviewedBy',
                'applicant' => fn (MorphTo $morph) => $morph->morphWith([StaffProfile::class => ['user', 'department']]),
            ])
            ->where('applicant_type', (new StaffProfile)->getMorphClass());

        if ($this->self()) {
            $query->where('applicant_id', auth()->user()?->staffProfile()->value('id') ?? 0);
        }

        return $query;
    }

    public function defaultSort(): array
    {
        return ['start_date', 'desc'];
    }

    public function columns(): array
    {
        $format = tenant()?->setting('date_format') ?? 'd M Y';

        return array_values(array_filter([
            $this->self() ? null : Column::make('staff', __('Staff'))->virtual()->searchable()
                ->format(fn ($value, LeaveRequest $request) => $request->applicant instanceof StaffProfile
                    ? Cells::person($request->applicant->user, collect([$request->applicant->designation, $request->applicant->department?->name])->filter()->implode(' · '))
                    : null),
            Column::make('start_date', __('Dates'))->sortable()
                ->format(fn ($value, LeaveRequest $request) => new HtmlString(
                    '<span class="text-nowrap">'.e($request->start_date->format($format)).($request->end_date->ne($request->start_date) ? ' – '.e($request->end_date->format($format)) : '').'</span>'
                    .'<small class="d-block text-muted">'.e((float) $request->days === 0.5 ? __('Half day') : trans_choice(':count day|:count days', (int) ceil((float) $request->days), ['count' => (float) $request->days])).'</small>',
                )),
            Column::make('leave_type_id', __('Type'))
                ->format(fn ($value, LeaveRequest $request) => new HtmlString(
                    e($request->leaveType?->name).($request->leaveType && ! $request->leaveType->is_paid ? ' <span class="badge bg-warning-subtle text-warning">'.e(__('Unpaid')).'</span>' : ''),
                )),
            Column::make('reason', __('Reason'))->hiddenOnMobile()
                ->format(fn (?string $reason, LeaveRequest $request) => new HtmlString(
                    e(Str::limit((string) $reason, 70))
                    .($request->attachment ? ' <a href="'.e(route('tenant.leave.attachment', $request->id)).'" target="_blank" rel="noopener" title="'.e(__('Attachment')).'"><i class="ri-attachment-2"></i></a>' : ''),
                )),
            Column::make('status', __('Status'))->sortable()
                ->format(fn (LeaveStatus $status, LeaveRequest $request) => new HtmlString(
                    sprintf('<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', $status->color(), e($status->label()))
                    .($request->reviewedBy ? '<small class="d-block text-muted mt-1">'.e(__('By :name', ['name' => $request->reviewedBy->name])).'</small>' : '')
                    .($request->review_remarks ? '<small class="d-block text-muted">'.e(Str::limit($request->review_remarks, 60)).'</small>' : ''),
                )),
        ]));
    }

    public function filters(): array
    {
        return array_values(array_filter([
            Filter::make('status', __('Status'))->options(LeaveStatus::class),
            Filter::make('leave_type_id', __('Type'))
                ->options(LeaveType::query()->withTrashed()->whereIn('applies_to', [LeaveAppliesTo::Staff, LeaveAppliesTo::Both])->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
            $this->self() ? null : Filter::make('department_id', __('Department'))
                ->options(Department::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all())
                ->query(fn (Builder $query, string $value) => $query->whereIn('applicant_id', StaffProfile::query()->where('department_id', (int) $value)->select('id'))),
        ]));
    }

    public function actions(): array
    {
        $leave = app(StaffLeave::class);

        if ($this->self()) {
            return [
                Action::make('cancel', __('Cancel request'))
                    ->icon('ri-close-circle-line')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $request) => $request->isPending())
                    ->confirm(__('HR will no longer see this request.'), __('Cancel this leave request?'))
                    ->handle(fn (LeaveRequest $request) => $leave->cancel($request, auth()->user()))
                    ->success(__('Leave request cancelled.')),
            ];
        }

        $reviewable = fn (User $user, LeaveRequest $request) => $request->isPending() && $leave->canReview($user, $request);

        return [
            Action::make('approve', __('Approve'))
                ->icon('ri-check-line')
                ->color('success')
                ->can($reviewable)
                ->modal('forms.staff-leave-review', fn (LeaveRequest $request) => ['request' => $request->id, 'decision' => 'approve']),

            Action::make('reject', __('Reject'))
                ->icon('ri-close-line')
                ->color('danger')
                ->can($reviewable)
                ->modal('forms.staff-leave-review', fn (LeaveRequest $request) => ['request' => $request->id, 'decision' => 'reject']),

            Action::make('cancel', __('Cancel request'))
                ->icon('ri-close-circle-line')
                ->color('secondary')
                ->can(fn (User $user, LeaveRequest $request) => $request->isPending() && $user->can('leave.manage'))
                ->confirm(__('The request is withdrawn on the staff member\'s behalf.'), __('Cancel this leave request?'))
                ->handle(fn (LeaveRequest $request) => $leave->cancel($request, auth()->user()))
                ->success(__('Leave request cancelled.')),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('reason', 'like', $term)
            ->orWhereHasMorph('applicant', [StaffProfile::class], fn (Builder $staff) => $staff
                ->where('employee_id', 'like', $term)
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term))));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by name or reason…');
    }

    public function emptyTitle(): string
    {
        return $this->self() ? __('You haven\'t applied for leave') : __('No leave requests');
    }

    public function emptyMessage(): ?string
    {
        return $this->self() ? __('Apply for leave and HR will be notified.') : __('Requests from staff appear here for approval.');
    }
}
