<?php

namespace App\Tables;

use App\Enums\LeaveAppliesTo;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Leave\StudentLeave;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Student leave requests. Two views of the same list:
 *  - ['scope' => 'reviewer'] (teacher portal): requests the user may review;
 *  - ['scope' => 'parent'] (parent portal): the signed-in parent's children.
 */
class StudentLeaveTable extends Table
{
    private function scope(): string
    {
        return ($this->params['scope'] ?? null) === 'parent' ? 'parent' : 'reviewer';
    }

    public function authorize(): void
    {
        if ($this->scope() === 'parent') {
            abort_unless(auth()->user()?->canAccessPortal('parent') && auth()->user()->parentProfile()->exists(), 403);

            return;
        }

        parent::authorize();
    }

    public function permission(): ?string
    {
        return 'student-leave.view';
    }

    public function query(): Builder
    {
        $query = LeaveRequest::query()
            ->with([
                'leaveType',
                'requestedBy',
                'reviewedBy',
                'applicant' => fn (MorphTo $morph) => $morph->morphWith([StudentProfile::class => ['user', 'schoolClass', 'section']]),
            ]);

        $leave = app(StudentLeave::class);
        $user = auth()->user();

        if ($this->scope() === 'parent') {
            $parent = $user?->parentProfile()->first();
            $parent ? $leave->scopeForParent($query, $parent) : $query->whereRaw('1 = 0');
        } else {
            $user ? $leave->scopeForReviewer($query, $user) : $query->whereRaw('1 = 0');
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

        return [
            Column::make('student', __('Student'))->virtual()->searchable()
                ->format(fn ($value, LeaveRequest $request) => $request->applicant instanceof StudentProfile
                    ? new HtmlString('<span class="fw-medium text-body d-block">'.e($request->applicant->user?->name).'</span>'
                        .'<small class="text-muted">'.e($request->applicant->placementLabel() ?? '').'</small>')
                    : null),
            Column::make('start_date', __('Dates'))->sortable()
                ->format(fn ($value, LeaveRequest $request) => new HtmlString(
                    '<span class="text-nowrap">'.e($request->start_date->format($format)).($request->end_date->ne($request->start_date) ? ' – '.e($request->end_date->format($format)) : '').'</span>'
                    .'<small class="d-block text-muted">'.e(trans_choice(':count school day|:count school days', (int) ceil((float) $request->days), ['count' => (float) $request->days])).'</small>',
                )),
            Column::make('leave_type_id', __('Type'))->hiddenOnMobile()
                ->format(fn ($value, LeaveRequest $request) => $request->leaveType?->name),
            Column::make('reason', __('Reason'))->hiddenOnMobile()
                ->format(fn (?string $reason) => Str::limit((string) $reason, 70)),
            Column::make('requested_by', __('Requested by'))->hiddenOnMobile()
                ->format(fn ($value, LeaveRequest $request) => $request->requestedBy?->name),
            Column::make('status', __('Status'))->sortable()
                ->format(fn (LeaveStatus $status, LeaveRequest $request) => new HtmlString(
                    sprintf('<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', $status->color(), e($status->label()))
                    .($request->review_remarks ? '<small class="d-block text-muted mt-1">'.e(Str::limit($request->review_remarks, 60)).'</small>' : ''),
                )),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('status', __('Status'))->options(LeaveStatus::class),
            Filter::make('leave_type_id', __('Type'))
                ->options(LeaveType::query()->withTrashed()->whereIn('applies_to', [LeaveAppliesTo::Student, LeaveAppliesTo::Both])->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
        ];
    }

    public function actions(): array
    {
        if ($this->scope() === 'parent') {
            return [
                Action::make('cancel', __('Cancel request'))
                    ->icon('ri-close-circle-line')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $request) => $request->isPending())
                    ->confirm(__('The school will no longer see this request.'), __('Cancel this leave request?'))
                    ->handle(fn (LeaveRequest $request) => app(StudentLeave::class)->cancel($request, auth()->user()))
                    ->success(__('Leave request cancelled.')),
            ];
        }

        $reviewable = fn (User $user, LeaveRequest $request) => $request->isPending() && app(StudentLeave::class)->canReview($user, $request);

        return [
            Action::make('approve', __('Approve'))
                ->icon('ri-check-line')
                ->color('success')
                ->can($reviewable)
                ->modal('forms.leave-review', fn (LeaveRequest $request) => ['request' => $request->id, 'decision' => 'approve']),

            Action::make('reject', __('Reject'))
                ->icon('ri-close-line')
                ->color('danger')
                ->can($reviewable)
                ->modal('forms.leave-review', fn (LeaveRequest $request) => ['request' => $request->id, 'decision' => 'reject']),
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
            ->orWhereHasMorph('applicant', [StudentProfile::class], fn (Builder $student) => $student
                ->where('admission_number', 'like', $term)
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term))));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by student or reason…');
    }

    public function emptyTitle(): string
    {
        return __('No leave requests');
    }

    public function emptyMessage(): ?string
    {
        return $this->scope() === 'parent'
            ? __('Apply for leave when your child will be away from school.')
            : __('Requests from parents of your students appear here.');
    }
}
