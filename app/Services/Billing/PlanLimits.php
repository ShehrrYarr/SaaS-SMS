<?php

namespace App\Services\Billing;

use App\Models\School;

/**
 * The student and staff caps of a school's plan, and how much of them the
 * school uses. Admissions and staff screens call ensureCanAdd() before
 * creating records.
 */
class PlanLimits
{
    public const RESOURCES = ['students', 'staff'];

    /**
     * @param  'students'|'staff'  $resource
     */
    public function limit(School $school, string $resource): ?int
    {
        return $school->plan?->limit($resource);
    }

    /**
     * @param  'students'|'staff'  $resource
     */
    public function used(School $school, string $resource): int
    {
        return match ($resource) {
            'students' => $school->activeStudents()->count(),
            'staff' => $school->staff()->count(),
        };
    }

    /**
     * How many more can be added, null for unlimited.
     *
     * @param  'students'|'staff'  $resource
     */
    public function remaining(School $school, string $resource): ?int
    {
        $limit = $this->limit($school, $resource);

        return $limit === null ? null : max(0, $limit - $this->used($school, $resource));
    }

    /**
     * @param  'students'|'staff'  $resource
     *
     * @throws PlanLimitExceeded
     */
    public function ensureCanAdd(School $school, string $resource, int $count = 1): void
    {
        $remaining = $this->remaining($school, $resource);

        if ($remaining === null || $count <= $remaining) {
            return;
        }

        $limit = $this->limit($school, $resource);

        throw new PlanLimitExceeded(match ($resource) {
            'students' => __('Your plan allows :limit active students and :remaining more can be added. Ask the platform administrator to upgrade the plan.', ['limit' => $limit, 'remaining' => $remaining]),
            'staff' => __('Your plan allows :limit staff members and :remaining more can be added. Ask the platform administrator to upgrade the plan.', ['limit' => $limit, 'remaining' => $remaining]),
        });
    }

    /**
     * Usage of every capped resource, for meters.
     *
     * @return array<'students'|'staff', array{used: int, limit: int|null}>
     */
    public function usage(School $school): array
    {
        $usage = [];

        foreach (self::RESOURCES as $resource) {
            $usage[$resource] = ['used' => $this->used($school, $resource), 'limit' => $this->limit($school, $resource)];
        }

        return $usage;
    }
}
