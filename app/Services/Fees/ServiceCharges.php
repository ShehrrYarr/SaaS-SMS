<?php

namespace App\Services\Fees;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceItem;
use App\Models\StudentHostel;
use App\Models\StudentProfile;
use App\Models\StudentTransport;
use App\Support\Billing\PlanFeatures;
use Illuminate\Database\Eloquent\Builder;

/**
 * Monthly charges for school services, billed on fee invoices next to the
 * fees: the transport fare of the route a student rides, and the fee of the
 * hostel room they stay in.
 *
 * A student is charged for a month when they used the service on any day of
 * it, at the rate of the route or room they had last that month, once per
 * month (unless that invoice was cancelled). No discounts apply.
 */
class ServiceCharges
{
    public const TRANSPORT = 'transport';

    public const HOSTEL = 'hostel';

    /**
     * Services the school's plan includes.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $plan = tenant()?->plan;

        return array_values(array_filter([self::TRANSPORT, self::HOSTEL], fn (string $kind) => PlanFeatures::allows($plan, "{$kind}.view")));
    }

    public static function label(string $kind): string
    {
        return $kind === self::TRANSPORT ? __('Transport fares') : __('Hostel fees');
    }

    /**
     * What each service would bill the student for the month, and whether it
     * already has been. Services the student didn't use (or that cost
     * nothing) are left out.
     *
     * @param  list<string>|null  $kinds  null for every available service
     * @return array<string, array{line: array<string, mixed>, billed: bool}>
     */
    public function preview(StudentProfile $student, string $period, ?array $kinds = null): array
    {
        $month = FeeSchedule::periodStart($period);
        $kinds = array_values(array_intersect($kinds ?? self::available(), self::available()));
        $charges = [];

        if (in_array(self::TRANSPORT, $kinds, true)) {
            $assignment = StudentTransport::query()
                ->with(['route' => fn ($query) => $query->withTrashed()])
                ->where('student_profile_id', $student->id)
                ->duringMonth($month)
                ->latest('start_date')->latest('id')
                ->first();

            if ($assignment?->route && (float) $assignment->route->fare > 0) {
                $charges[self::TRANSPORT] = [
                    'line' => $this->line(
                        __('Transport: :route', ['route' => $assignment->route->name]).' — '.FeeSchedule::periodLabel($period),
                        $assignment->route->fare,
                        $assignment->getMorphClass(),
                        $assignment->id,
                    ),
                    'billed' => $this->alreadyBilled($student, self::TRANSPORT, $period),
                ];
            }
        }

        if (in_array(self::HOSTEL, $kinds, true)) {
            $stay = StudentHostel::query()
                ->with(['room' => fn ($query) => $query->withTrashed()->with(['building' => fn ($query) => $query->withTrashed()])])
                ->where('student_profile_id', $student->id)
                ->duringMonth($month)
                ->latest('check_in')->latest('id')
                ->first();

            if ($stay?->room && (float) $stay->room->fee > 0) {
                $charges[self::HOSTEL] = [
                    'line' => $this->line(
                        __('Hostel: :room', ['room' => $stay->room->label()]).' — '.FeeSchedule::periodLabel($period),
                        $stay->room->fee,
                        $stay->getMorphClass(),
                        $stay->id,
                    ),
                    'billed' => $this->alreadyBilled($student, self::HOSTEL, $period),
                ];
            }
        }

        return $charges;
    }

    /**
     * Invoice lines for the services not yet billed for the month.
     *
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    public function linesFor(StudentProfile $student, string $period, array $kinds): array
    {
        if ($kinds === []) {
            return [];
        }

        return array_values(array_map(
            fn (array $charge) => $charge['line'],
            array_filter($this->preview($student, $period, $kinds), fn (array $charge) => ! $charge['billed']),
        ));
    }

    public function alreadyBilled(StudentProfile $student, string $kind, string $period): bool
    {
        return InvoiceItem::query()
            ->where('billable_type', self::morph($kind))
            ->whereHas('invoice', fn (Builder $query) => $query
                ->where('student_profile_id', $student->id)
                ->where('billing_period', $period)
                ->where('status', '!=', InvoiceStatus::Cancelled))
            ->exists();
    }

    /**
     * Active students of the classes (every class when empty) who used the
     * service during the month.
     *
     * @param  list<int|string>  $classIds
     */
    public function usersCount(string $kind, string $period, array $classIds = []): int
    {
        $month = FeeSchedule::periodStart($period);
        $model = $kind === self::TRANSPORT ? StudentTransport::class : StudentHostel::class;

        return StudentProfile::query()
            ->active()
            ->when($classIds !== [], fn ($query) => $query->whereIn('class_id', $classIds))
            ->whereIn('id', $model::query()->duringMonth($month)->select('student_profile_id'))
            ->count();
    }

    private static function morph(string $kind): string
    {
        return $kind === self::TRANSPORT ? (new StudentTransport)->getMorphClass() : (new StudentHostel)->getMorphClass();
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $description, mixed $amount, string $type, int $id): array
    {
        return [...FeeCalculator::customLine($description, $amount), 'billable_type' => $type, 'billable_id' => $id];
    }
}
