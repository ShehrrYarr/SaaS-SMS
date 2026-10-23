<?php

namespace App\Tables;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessGuard;
use App\Services\Students\StudentRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every student in the school, with their class and primary guardian.
 */
class StudentsTable extends Table
{
    public function permission(): ?string
    {
        return 'students.view';
    }

    public function query(): Builder
    {
        return StudentProfile::query()->with(['user', 'schoolClass', 'section', 'parents.user']);
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('name', __('Student'))->virtual()->searchable()
                ->sortable(fn (Builder $query, string $direction) => $query->orderBy(
                    User::query()->select('name')->whereColumn('users.id', 'student_profiles.user_id')->limit(1),
                    $direction,
                ))
                ->format(fn ($value, StudentProfile $student) => Cells::person(
                    $student->user,
                    $student->admission_number,
                    route('admin.students.show', $student->id),
                )),
            Column::make('class_id', __('Class'))
                ->sortable(fn (Builder $query, string $direction) => $query
                    ->orderBy(SchoolClass::query()->select('numeric_level')->whereColumn('classes.id', 'student_profiles.class_id')->limit(1), $direction)
                    ->orderBy(SchoolClass::query()->select('name')->whereColumn('classes.id', 'student_profiles.class_id')->limit(1), $direction))
                ->format(fn ($value, StudentProfile $student) => $student->placementLabel()),
            Column::make('roll_number', __('Roll no.'))->sortable()->hiddenOnMobile(),
            Column::make('guardian', __('Guardian'))->virtual()->hiddenOnMobile()
                ->format(function ($value, StudentProfile $student) {
                    $guardian = $student->parents->firstWhere('pivot.is_primary', true) ?? $student->parents->first();

                    return Cells::contact($guardian?->user, $guardian?->pivot->relation?->label());
                }),
            Column::make('gender', __('Gender'))->hiddenOnMobile(),
            Column::make('status', __('Status'))->sortable()->badge(),
        ];
    }

    public function filters(): array
    {
        $classes = SchoolClass::query()->ordered()->with(['sections' => fn ($query) => $query->ordered()])->get();

        $sections = [];
        foreach ($classes as $class) {
            foreach ($class->sections as $section) {
                $sections[(string) $section->id] = $class->name.' · '.$section->name;
            }
        }

        return [
            Filter::make('class_id', __('Class'))
                ->options($classes->mapWithKeys(fn (SchoolClass $class) => [(string) $class->id => $class->name])->all()),
            Filter::make('section_id', __('Section'))->options($sections),
            Filter::make('status', __('Status'))->options(StudentStatus::class),
            Filter::make('gender', __('Gender'))->options(Gender::class),
        ];
    }

    public function actions(): array
    {
        return [
            Action::make('view', __('View profile'))
                ->icon('ri-eye-line')
                ->url(fn (StudentProfile $student) => route('admin.students.show', $student->id)),

            Action::make('edit', __('Edit'))
                ->icon('ri-pencil-line')
                ->can('students.edit')
                ->url(fn (StudentProfile $student) => route('admin.students.edit', $student->id)),

            Action::make('status', __('Change status'))
                ->icon('ri-user-settings-line')
                ->can('students.edit')
                ->modal('forms.student-status', fn (StudentProfile $student) => ['student' => $student->id]),

            Action::make('reset-password', __('Reset password'))
                ->icon('ri-lock-password-line')
                ->color('warning')
                ->can(fn (User $editor, StudentProfile $student) => $editor->can('users.reset-password')
                    && $student->user && app(AccessGuard::class)->canManage($editor, $student->user))
                ->modal('forms.reset-user-password', fn (StudentProfile $student) => ['user' => $student->user_id]),

            Action::make('delete', __('Delete'))
                ->icon('ri-delete-bin-line')
                ->color('danger')
                ->can('students.delete')
                ->confirm(__('The student and their sign-in account are removed. Attendance, marks and fee records are kept.'), __('Delete this student?'))
                ->handle(fn (StudentProfile $student) => app(StudentRecords::class)->delete($student, auth()->user()))
                ->success(__('Student deleted.')),
        ];
    }

    public function bulkActions(): array
    {
        return [
            BulkAction::make('delete', __('Delete selected'))
                ->icon('ri-delete-bin-line')
                ->color('danger')
                ->can('students.delete')
                ->confirm(__('Delete the selected students and their sign-in accounts? Their records are kept.'))
                ->handle(function (Collection $students): int {
                    $students->each(fn (StudentProfile $student) => app(StudentRecords::class)->delete($student, auth()->user()));

                    return $students->count();
                })
                ->success(fn (int $count) => trans_choice('{1} :count student deleted.|[2,*] :count students deleted.', $count, ['count' => $count])),
        ];
    }

    /**
     * Find students by name, admission number, username or phone.
     */
    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('admission_number', 'like', $term)
            ->orWhere('roll_number', $search)
            ->orWhereHas('user', fn (Builder $user) => $user
                ->where('name', 'like', $term)
                ->orWhere('username', 'like', $term)
                ->orWhere('phone', 'like', $term)));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by name, admission no. or username…');
    }

    public function emptyTitle(): string
    {
        return __('No students yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Admit students one by one, or import them from a spreadsheet.');
    }
}
