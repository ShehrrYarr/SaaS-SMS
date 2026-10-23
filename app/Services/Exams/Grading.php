<?php

namespace App\Services\Exams;

use App\Models\Examination;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;

/**
 * Turns marks into grades with an examination's grade scale (or the
 * school's default scale when the exam has none).
 */
class Grading
{
    /** @var array<int, GradeScale|null> */
    private array $scales = [];

    public function scaleFor(Examination $exam): ?GradeScale
    {
        $key = (int) $exam->grade_scale_id;

        return $this->scales[$key] ??= ($exam->grade_scale_id
            ? GradeScale::query()->with('items')->find($exam->grade_scale_id)
            : null)
            ?? GradeScale::query()->with('items')->where('is_default', true)->first()
            ?? GradeScale::query()->with('items')->oldest('id')->first();
    }

    /**
     * Percentage of marks, rounded to two places; null when nothing is out of.
     */
    public static function percentage(float $obtained, float $max): ?float
    {
        return $max > 0 ? round($obtained / $max * 100, 2) : null;
    }

    public function gradeFor(Examination $exam, ?float $percentage): ?GradeScaleItem
    {
        return $percentage === null ? null : $this->scaleFor($exam)?->gradeFor($percentage);
    }

    /**
     * Grade and grade point for one subject's marks.
     *
     * @return array{grade: string|null, grade_point: float|null}
     */
    public function forMarks(Examination $exam, ?float $obtained, float $max, bool $absent = false): array
    {
        if ($absent || $obtained === null) {
            $item = $absent ? $this->gradeFor($exam, 0) : null;

            return ['grade' => $item?->grade, 'grade_point' => $item?->grade_point !== null ? (float) $item->grade_point : null];
        }

        $item = $this->gradeFor($exam, self::percentage($obtained, $max));

        return ['grade' => $item?->grade, 'grade_point' => $item?->grade_point !== null ? (float) $item->grade_point : null];
    }
}
