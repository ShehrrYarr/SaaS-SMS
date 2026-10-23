<?php

namespace App\Services\Academics;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\SubjectClass;
use Illuminate\Support\Facades\DB;

/**
 * Which subjects a class studies in a year, and who teaches each section.
 *
 * A class with sections gets one allocation per section (so sections can
 * have different teachers); a class without sections gets one allocation
 * with no section.
 */
class SubjectAllocator
{
    /**
     * Current allocations as subject_id => ['periods' => ?int, 'teachers' => [section key => ?teacher id]].
     * The section key is the section id, or "all" for a class without sections.
     *
     * @return array<int, array{periods: int|null, teachers: array<string, int|null>}>
     */
    public function current(AcademicYear $year, SchoolClass $class): array
    {
        $sectionIds = $class->sections()->pluck('id')->all();
        $result = [];

        $allocations = SubjectClass::query()
            ->where('academic_year_id', $year->id)
            ->where('class_id', $class->id)
            ->get();

        foreach ($allocations as $allocation) {
            $row = $result[$allocation->subject_id] ?? ['periods' => $allocation->periods_per_week, 'teachers' => []];

            if ($allocation->section_id === null) {
                // A whole-class allocation covers every section.
                foreach ($sectionIds ?: ['all'] as $key) {
                    $row['teachers'][(string) $key] ??= $allocation->teacher_id;
                }
            } else {
                $row['teachers'][(string) $allocation->section_id] = $allocation->teacher_id;
            }

            $result[$allocation->subject_id] = $row;
        }

        return $result;
    }

    /**
     * Replace the class's allocations for the year.
     *
     * @param  array<int, array{periods?: int|string|null, teachers?: array<string, int|string|null>}>  $subjects  taught subjects only
     */
    public function sync(AcademicYear $year, SchoolClass $class, array $subjects): void
    {
        $sectionIds = $class->sections()->pluck('id')->all();
        $blank = fn ($value) => $value === '' || $value === null ? null : (int) $value;

        DB::transaction(function () use ($year, $class, $subjects, $sectionIds, $blank) {
            $existing = SubjectClass::withTrashed()
                ->where('academic_year_id', $year->id)
                ->where('class_id', $class->id)
                ->get();

            $wanted = [];

            foreach ($subjects as $subjectId => $row) {
                foreach ($sectionIds ?: [null] as $sectionId) {
                    $wanted[] = [
                        'subject_id' => (int) $subjectId,
                        'section_id' => $sectionId,
                        'teacher_id' => $blank($row['teachers'][(string) ($sectionId ?? 'all')] ?? null),
                        'periods_per_week' => $blank($row['periods'] ?? null),
                    ];
                }
            }

            $kept = [];

            foreach ($wanted as $values) {
                $match = $existing->first(fn (SubjectClass $allocation) => $allocation->subject_id === $values['subject_id']
                    && $allocation->section_id === $values['section_id']);

                if ($match) {
                    $match->trashed() && $match->restore();
                    $match->update($values);
                    $kept[] = $match->id;

                    continue;
                }

                $kept[] = SubjectClass::query()->create($values + [
                    'academic_year_id' => $year->id,
                    'class_id' => $class->id,
                ])->id;
            }

            $existing->reject(fn (SubjectClass $allocation) => in_array($allocation->id, $kept, true) || $allocation->trashed())
                ->each->delete();
        });
    }
}
