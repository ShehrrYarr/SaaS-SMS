<?php

namespace App\Services\Fees;

use App\Enums\AdjustmentType;
use App\Enums\FeeFrequency;
use App\Models\FeeStructure;
use App\Models\ParentStudent;
use App\Models\SiblingDiscountRule;
use App\Models\StudentFeeDiscount;
use App\Models\StudentProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Works out an invoice line: a fee's amount less the reductions that apply
 * to the student, following the school's fee options (Settings → Fee
 * options) and each fee's own switches.
 *
 *  - Discounts and scholarships assigned to the student, valid on the
 *    billing date, for that fee or for any fee. A fixed discount comes off
 *    every fee it applies to.
 *  - Sibling discount: children who share a parent are ranked eldest first
 *    (date of birth, then admission). The rule with the highest position at
 *    or below the child's applies; rules for a particular fee take
 *    precedence over rules for any fee.
 *
 * Reductions add up, but never beyond the fee itself.
 *
 * Holds per-student lookups for the lifetime of the instance, so resolve a
 * fresh one per request or job.
 */
class FeeCalculator
{
    /** @var array<int, Collection<int, StudentFeeDiscount>> */
    private array $discounts = [];

    /** @var array<int, int> */
    private array $positions = [];

    /** @var Collection<int, SiblingDiscountRule>|null */
    private ?Collection $siblingRules = null;

    /**
     * @return array{fee_structure_id: int, description: string, amount: float, discount_amount: float, net_amount: float, discount_breakdown: list<array<string, mixed>>|null}
     */
    public function line(StudentProfile $student, FeeStructure $fee, CarbonInterface $on, ?string $period = null): array
    {
        $amount = self::cents($fee->amount);
        $reductions = [];
        $school = tenant();

        if ($fee->allow_discounts && $school->setting('fees.discounts_enabled')) {
            foreach ($this->discountsFor($student, $on) as $assignment) {
                $discount = $assignment->discount;

                if ($discount->fee_structure_id === null || $discount->fee_structure_id === $fee->id) {
                    $reductions[] = [
                        'source' => 'discount',
                        'id' => $discount->id,
                        'label' => $discount->name,
                        'type' => $discount->type->value,
                        'value' => (float) $discount->value,
                        'cents' => self::reduction($amount, $discount->type, $discount->value),
                    ];
                }
            }
        }

        if ($fee->allow_sibling_discount && $school->setting('fees.sibling_discount_enabled')) {
            $position = $this->childPosition($student);
            $rule = $position >= 2 ? $this->siblingRuleFor($fee, $position) : null;

            if ($rule) {
                $reductions[] = [
                    'source' => 'sibling',
                    'id' => $rule->id,
                    'label' => __('Sibling discount (child :position)', ['position' => $position]),
                    'type' => $rule->type->value,
                    'value' => (float) $rule->value,
                    'cents' => self::reduction($amount, $rule->type, $rule->value),
                ];
            }
        }

        // Cap the total at the fee, trimming the last reductions first.
        $left = $amount;
        foreach ($reductions as $index => $reduction) {
            $reductions[$index]['cents'] = min($reduction['cents'], $left);
            $left -= $reductions[$index]['cents'];
        }

        $reductions = array_values(array_filter($reductions, fn (array $reduction) => $reduction['cents'] > 0));
        $discount = $amount - $left;

        return [
            'fee_structure_id' => $fee->id,
            'description' => $period && $fee->frequency !== FeeFrequency::OneTime
                ? $fee->title.' — '.FeeSchedule::periodLabel($period)
                : $fee->title,
            'amount' => round($amount / 100, 2),
            'discount_amount' => round($discount / 100, 2),
            'net_amount' => round(($amount - $discount) / 100, 2),
            'discount_breakdown' => $reductions === [] ? null : array_map(function (array $reduction) {
                $reduction['amount'] = round($reduction['cents'] / 100, 2);
                unset($reduction['cents']);

                return $reduction;
            }, $reductions),
        ];
    }

    /**
     * A line for something that isn't a set fee (a lost library book, a
     * trip): no reductions.
     *
     * @return array{fee_structure_id: null, description: string, amount: float, discount_amount: float, net_amount: float, discount_breakdown: null}
     */
    public static function customLine(string $description, float|string $amount): array
    {
        $amount = round(self::cents($amount) / 100, 2);

        return [
            'fee_structure_id' => null,
            'description' => trim($description),
            'amount' => $amount,
            'discount_amount' => 0.0,
            'net_amount' => $amount,
            'discount_breakdown' => null,
        ];
    }

    /**
     * The student's discount assignments valid on a date.
     *
     * @return Collection<int, StudentFeeDiscount>
     */
    public function discountsFor(StudentProfile $student, CarbonInterface $on): Collection
    {
        $this->discounts[$student->id] ??= StudentFeeDiscount::query()
            ->with('discount')
            ->where('student_profile_id', $student->id)
            ->whereHas('discount', fn ($query) => $query->where('is_active', true))
            ->get();

        $date = $on->toDateString();

        return $this->discounts[$student->id]
            ->filter(fn (StudentFeeDiscount $assignment) => ($assignment->valid_from === null || $assignment->valid_from->toDateString() <= $date)
                && ($assignment->valid_until === null || $assignment->valid_until->toDateString() >= $date))
            ->sortBy('id')
            ->values();
    }

    /**
     * 1 for the eldest active child of the student's parents (or a student
     * with no siblings), 2 for the next, and so on.
     */
    public function childPosition(StudentProfile $student): int
    {
        if (isset($this->positions[$student->id])) {
            return $this->positions[$student->id];
        }

        $parents = ParentStudent::query()->where('student_profile_id', $student->id)->select('parent_profile_id');

        $siblings = StudentProfile::query()
            ->where(fn ($query) => $query->active()->orWhere('id', $student->id))
            ->whereIn('id', ParentStudent::query()->whereIn('parent_profile_id', $parents)->select('student_profile_id'))
            ->get(['id', 'dob', 'admission_date'])
            ->sortBy(fn (StudentProfile $sibling) => [
                $sibling->dob?->toDateString() ?? '9999-12-31',
                $sibling->admission_date?->toDateString() ?? '9999-12-31',
                $sibling->id,
            ])
            ->values();

        $index = $siblings->search(fn (StudentProfile $sibling) => $sibling->id === $student->id);

        return $this->positions[$student->id] = $index === false ? 1 : $index + 1;
    }

    public function siblingRuleFor(FeeStructure $fee, int $position): ?SiblingDiscountRule
    {
        $this->siblingRules ??= SiblingDiscountRule::query()->where('is_active', true)->get();

        $specific = $this->siblingRules->where('fee_structure_id', $fee->id);
        $rules = $specific->isNotEmpty() ? $specific : $this->siblingRules->whereNull('fee_structure_id');

        return $rules->filter(fn (SiblingDiscountRule $rule) => $rule->child_position <= $position)
            ->sortByDesc('child_position')
            ->first();
    }

    public static function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function reduction(int $amount, AdjustmentType $type, mixed $value): int
    {
        return $type === AdjustmentType::Percentage
            ? (int) round($amount * min((float) $value, 100) / 100)
            : self::cents($value);
    }
}
