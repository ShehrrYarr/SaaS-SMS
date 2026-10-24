<?php

namespace App\Services\Transport;

use App\Enums\AllocationStatus;
use App\Models\StudentProfile;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Notifications\FamilyNotifier;
use App\Support\SelfService;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * School transport: vehicles, routes and the students who ride them.
 *
 * A student rides one route at a time, from a stop on it. A route seats as
 * many students as its vehicle holds (a route without a vehicle isn't
 * limited). Moving a student to another route ends the old assignment the
 * day before, so the history (and monthly billing) stays right.
 */
class Transport
{
    public function __construct(private readonly SchoolCalendar $calendar, private readonly FamilyNotifier $family) {}

    /**
     * @param  array{vehicle_number: string, model?: string|null, capacity: int, driver_name?: string|null, driver_phone?: string|null, driver_license?: string|null, status: string}  $data
     *
     * @throws ActionFailed
     */
    public function saveVehicle(array $data, ?TransportVehicle $vehicle = null): TransportVehicle
    {
        $values = [
            'vehicle_number' => mb_strtoupper(trim($data['vehicle_number'])),
            'model' => self::clean($data['model'] ?? null),
            'capacity' => (int) $data['capacity'],
            'driver_name' => self::clean($data['driver_name'] ?? null),
            'driver_phone' => self::clean($data['driver_phone'] ?? null),
            'driver_license' => self::clean($data['driver_license'] ?? null),
            'status' => $data['status'],
        ];

        if ($vehicle) {
            $busiest = $vehicle->routes()->withCount('activeAssignments')->get()->max('active_assignments_count') ?? 0;

            if ($values['capacity'] < $busiest) {
                throw new ActionFailed(trans_choice('A route on this vehicle has :count student, so it needs at least :count seat.|A route on this vehicle has :count students, so it needs at least :count seats.', $busiest, ['count' => $busiest]));
            }

            $vehicle->update($values);

            return $vehicle;
        }

        // A number used by a removed vehicle brings that vehicle back.
        $removed = TransportVehicle::onlyTrashed()->where('vehicle_number', $values['vehicle_number'])->first();

        if ($removed) {
            $removed->restore();
            $removed->update($values);

            return $removed;
        }

        return TransportVehicle::query()->create($values);
    }

    public function deleteVehicle(TransportVehicle $vehicle): void
    {
        DB::transaction(function () use ($vehicle) {
            $vehicle->routes()->update(['transport_vehicle_id' => null]);
            $vehicle->delete();
        });
    }

    /**
     * @param  array{name: string, transport_vehicle_id?: int|string|null, start_point?: string|null, end_point?: string|null, fare: float|string, description?: string|null, stops?: list<array{name?: string|null, time?: string|null}>}  $data
     *
     * @throws ActionFailed
     */
    public function saveRoute(array $data, ?TransportRoute $route = null): TransportRoute
    {
        $stops = collect($data['stops'] ?? [])
            ->map(fn (array $stop) => ['name' => trim((string) ($stop['name'] ?? '')), 'time' => filled($stop['time'] ?? null) ? substr((string) $stop['time'], 0, 5) : null])
            ->filter(fn (array $stop) => $stop['name'] !== '')
            ->unique(fn (array $stop) => mb_strtolower($stop['name']))
            ->values();

        $values = [
            'name' => trim($data['name']),
            'transport_vehicle_id' => filled($data['transport_vehicle_id'] ?? null) ? (int) $data['transport_vehicle_id'] : null,
            'start_point' => self::clean($data['start_point'] ?? null),
            'end_point' => self::clean($data['end_point'] ?? null),
            'fare' => round((float) $data['fare'], 2),
            'description' => self::clean($data['description'] ?? null),
            'stops' => $stops->all(),
        ];

        if ($route) {
            $riders = $route->activeAssignments()->get(['id', 'pickup_point']);
            $vehicle = $values['transport_vehicle_id'] ? TransportVehicle::query()->find($values['transport_vehicle_id']) : null;

            if ($vehicle && $vehicle->capacity < $riders->count()) {
                throw new ActionFailed(trans_choice(':vehicle seats :seats, but :count student rides this route.|:vehicle seats :seats, but :count students ride this route.', $riders->count(), ['vehicle' => $vehicle->vehicle_number, 'seats' => $vehicle->capacity, 'count' => $riders->count()]));
            }

            $names = $stops->map(fn (array $stop) => mb_strtolower($stop['name']));
            $stranded = $riders->filter(fn (StudentTransport $rider) => filled($rider->pickup_point) && ! $names->contains(mb_strtolower($rider->pickup_point)));

            if ($stranded->isNotEmpty()) {
                $stop = $stranded->first()->pickup_point;
                $count = $stranded->where('pickup_point', $stop)->count();

                throw new ActionFailed(trans_choice(':count student boards at :stop. Move them to another stop before removing it.|:count students board at :stop. Move them to another stop before removing it.', $count, ['count' => $count, 'stop' => $stop]));
            }

            $route->update($values);

            return $route;
        }

        $removed = TransportRoute::onlyTrashed()->where('name', $values['name'])->first();

        if ($removed) {
            $removed->restore();
            $removed->update($values);

            return $removed;
        }

        return TransportRoute::query()->create($values);
    }

    /**
     * @throws ActionFailed
     */
    public function deleteRoute(TransportRoute $route): void
    {
        $riders = $route->activeAssignments()->count();

        if ($riders > 0) {
            throw new ActionFailed(trans_choice(':count student rides :route. End or move their assignment first.|:count students ride :route. End or move their assignments first.', $riders, ['count' => $riders, 'route' => $route->name]));
        }

        $route->delete();
    }

    /**
     * Free seats on a route, or null when it has no vehicle (no limit).
     */
    public function seatsLeft(TransportRoute $route, ?int $ignoring = null): ?int
    {
        $route->loadMissing('vehicle');

        if (! $route->vehicle) {
            return null;
        }

        $riders = $route->activeAssignments()->when($ignoring, fn ($query) => $query->whereKeyNot($ignoring))->count();

        return max(0, $route->vehicle->capacity - $riders);
    }

    /**
     * @throws ActionFailed
     */
    public function assign(StudentProfile $student, TransportRoute $route, ?string $stop, CarbonInterface $from, User $by): StudentTransport
    {
        if (! $student->isActive()) {
            throw new ActionFailed(__('Only a current student can be given transport.'));
        }

        return DB::transaction(function () use ($student, $route, $stop, $from, $by) {
            $current = StudentTransport::query()->active()->with('route')->where('student_profile_id', $student->id)->lockForUpdate()->first();

            if ($current) {
                throw new ActionFailed(__(':name already rides :route. Change that assignment instead.', ['name' => $student->user?->name, 'route' => $current->route?->name]));
            }

            $route = TransportRoute::query()->lockForUpdate()->findOrFail($route->id);
            $this->ensureSeat($route);

            $assignment = StudentTransport::query()->create([
                'student_profile_id' => $student->id,
                'transport_route_id' => $route->id,
                'academic_year_id' => $this->calendar->yearFor($from)?->id,
                'pickup_point' => $this->stopName($route, $stop),
                'start_date' => $from->toDateString(),
                'status' => AllocationStatus::Active,
            ]);

            $this->log($assignment, $by, 'Transport assigned', ['student' => $student->user?->name, 'route' => $route->name, 'stop' => $assignment->pickup_point]);
            $this->announce($assignment->setRelation('route', $route));

            return $assignment;
        });
    }

    /**
     * Move a student to another route or stop from a date. A change of
     * stop, or a change dated on or before the start, edits the assignment;
     * a change of route later on ends it and starts a new one.
     *
     * @throws ActionFailed
     */
    public function change(StudentTransport $assignment, TransportRoute $route, ?string $stop, CarbonInterface $from, User $by): StudentTransport
    {
        if (! $assignment->isActive()) {
            throw new ActionFailed(__('This assignment has ended. Give the student transport again instead.'));
        }

        return DB::transaction(function () use ($assignment, $route, $stop, $from, $by) {
            $route = TransportRoute::query()->lockForUpdate()->findOrFail($route->id);
            $stopName = $this->stopName($route, $stop);
            $student = $assignment->student()->with('user')->first();

            if ($route->id === $assignment->transport_route_id) {
                $assignment->update(['pickup_point' => $stopName]);
                $this->log($assignment, $by, 'Transport stop changed', ['student' => $student?->user?->name, 'route' => $route->name, 'stop' => $stopName]);
                $this->announce($assignment->setRelation('route', $route));

                return $assignment;
            }

            $this->ensureSeat($route);

            if ($from->toDateString() <= $assignment->start_date->toDateString()) {
                $assignment->update(['transport_route_id' => $route->id, 'pickup_point' => $stopName, 'start_date' => $from->toDateString()]);
                $moved = $assignment;
            } else {
                $assignment->update(['status' => AllocationStatus::Ended, 'end_date' => $from->copy()->subDay()->toDateString()]);

                $moved = StudentTransport::query()->create([
                    'student_profile_id' => $assignment->student_profile_id,
                    'transport_route_id' => $route->id,
                    'academic_year_id' => $this->calendar->yearFor($from)?->id,
                    'pickup_point' => $stopName,
                    'start_date' => $from->toDateString(),
                    'status' => AllocationStatus::Active,
                ]);
            }

            $this->log($moved, $by, 'Transport route changed', ['student' => $student?->user?->name, 'route' => $route->name, 'stop' => $stopName, 'from' => $from->toDateString()]);
            $this->announce($moved->setRelation('route', $route));

            return $moved;
        });
    }

    /**
     * The student stops using school transport after $on.
     *
     * @throws ActionFailed
     */
    public function end(StudentTransport $assignment, CarbonInterface $on, ?User $by, bool $notify = true): void
    {
        if (! $assignment->isActive()) {
            throw new ActionFailed(__('This assignment has already ended.'));
        }

        if ($on->toDateString() < $assignment->start_date->toDateString()) {
            throw new ActionFailed(__('That is before the assignment started (:date).', ['date' => $assignment->start_date->format(tenant()->setting('date_format'))]));
        }

        $assignment->update(['status' => AllocationStatus::Ended, 'end_date' => $on->toDateString()]);
        $assignment->loadMissing(['route', 'student.user']);

        $this->log($assignment, $by, 'Transport ended', ['student' => $assignment->student?->user?->name, 'route' => $assignment->route?->name, 'on' => $on->toDateString()]);

        if ($notify) {
            $date = $on->format(tenant()->setting('date_format'));
            $this->family->notify($assignment->student_profile_id, fn (StudentProfile $student, string $portal) => new SystemNotification(
                __('School transport ended for :name', ['name' => $student->user?->name]),
                __('Last day on :route: :date.', ['route' => $assignment->route?->name, 'date' => $date]),
                SelfService::url($portal, 'services', $student),
                'ri-bus-2-line',
                'secondary',
            ), student: false);
        }
    }

    /**
     * @throws ActionFailed
     */
    private function ensureSeat(TransportRoute $route): void
    {
        if ($this->seatsLeft($route) === 0) {
            throw new ActionFailed(__(':route is full: its vehicle seats :seats. Use a bigger vehicle or another route.', ['route' => $route->name, 'seats' => $route->vehicle?->capacity]));
        }
    }

    /**
     * The stop as named on the route (any case). Required when the route has stops.
     *
     * @throws ActionFailed
     */
    private function stopName(TransportRoute $route, ?string $stop): ?string
    {
        $stop = self::clean($stop);

        if ($route->stopList()->isEmpty()) {
            return $stop;
        }

        $match = $route->stop($stop);

        if (! $match) {
            throw new ActionFailed(__('Choose one of the stops on :route.', ['route' => $route->name]));
        }

        return $match['name'];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(StudentTransport $assignment, ?User $by, string $event, array $properties): void
    {
        activity('transport')->performedOn($assignment)->causedBy($by)->withProperties(array_filter($properties))->log($event);
    }

    /**
     * Tell the family the route, stop and pickup time.
     */
    private function announce(StudentTransport $assignment): void
    {
        $route = $assignment->route;
        $time = $route?->pickupTime($assignment->pickup_point);

        $this->family->notify($assignment->student_profile_id, fn (StudentProfile $student, string $portal) => new SystemNotification(
            __('School transport for :name: :route', ['name' => $student->user?->name, 'route' => $route?->name]),
            $assignment->pickup_point
                ? ($time ? __('Pickup at :stop, :time.', ['stop' => $assignment->pickup_point, 'time' => $time]) : __('Pickup at :stop.', ['stop' => $assignment->pickup_point]))
                : null,
            SelfService::url($portal, 'services', $student),
            'ri-bus-2-line',
            'info',
        ), student: false);
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
