<?php

namespace App\Livewire\Concerns;

use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * A search-as-you-type student picker for forms. Pair with the
 * <x-form.student-picker> component:
 *
 *     uses([PicksStudent::class]);
 *     ...
 *     <x-form.student-picker :results="$this->studentResults" :selected="$studentLabel" :picked="$student_id" :search="$studentSearch" />
 *
 * The chosen student's id is in $student_id; re-check it on save with
 * pickedStudent(), since the client can send any id.
 */
trait PicksStudent
{
    public string $studentSearch = '';

    #[Locked]
    public string $student_id = '';

    #[Locked]
    public string $studentLabel = '';

    /**
     * Students the picker offers: all of them (including those who have
     * left, who may still owe fees).
     *
     * @return Builder<StudentProfile>
     */
    protected function pickableStudents(): Builder
    {
        return StudentProfile::query();
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    #[Computed]
    public function studentResults(): Collection
    {
        $term = trim($this->studentSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $this->pickableStudents()
            ->with(['user', 'schoolClass', 'section'])
            ->where(fn ($query) => $query->where('admission_number', 'like', $like)
                ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                ->orWhereHas('parents.user', fn ($user) => $user->where('phone', 'like', $like)))
            ->limit(8)
            ->get();
    }

    public function pickStudent(int $id): void
    {
        $student = $this->pickableStudents()->with(['user', 'schoolClass', 'section'])->findOrFail($id);

        $this->student_id = (string) $student->id;
        $this->studentLabel = static::studentLabel($student);
        $this->studentSearch = '';
        $this->resetErrorBag('student_id');

        if (method_exists($this, 'studentPicked')) {
            $this->studentPicked($student);
        }
    }

    public function clearStudent(): void
    {
        $this->student_id = '';
        $this->studentLabel = '';
    }

    protected function setPickedStudent(StudentProfile $student): void
    {
        $student->loadMissing(['user', 'schoolClass', 'section']);

        $this->student_id = (string) $student->id;
        $this->studentLabel = static::studentLabel($student);
    }

    protected function pickedStudent(): ?StudentProfile
    {
        return $this->student_id === '' ? null : $this->pickableStudents()->with('user')->find((int) $this->student_id);
    }

    protected static function studentLabel(StudentProfile $student): string
    {
        return collect([$student->user?->name, $student->admission_number, $student->placementLabel()])->filter()->implode(' · ');
    }
}
