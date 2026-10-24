<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Models\StudentProfile;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Services\Transport\Transport;
use App\Tables\ActionFailed;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoData;

/**
 * School buses: four vehicles (one in the workshop), three morning routes,
 * and students on the stop nearest home. One family moves house and changes
 * route; one stops using the bus.
 */
trait RunsTransport
{
    /** @var array<string, TransportRoute> R1, R2, R3 */
    private array $routes = [];

    private function setUpTransport(): void
    {
        $this->as($this->people['bilal'], function () {
            $transport = app(Transport::class);

            $coaster = $transport->saveVehicle(['vehicle_number' => 'LEB-1234', 'model' => 'Toyota Coaster', 'capacity' => 26, 'driver_name' => 'Akram Shah', 'driver_phone' => '0300-1234567', 'driver_license' => 'LHR-88231', 'status' => 'active']);
            $coaster2 = $transport->saveVehicle(['vehicle_number' => 'LES-2468', 'model' => 'Toyota Coaster', 'capacity' => 26, 'driver_name' => 'Ghulam Murtaza', 'driver_phone' => '0333-4455667', 'driver_license' => 'LHR-61907', 'status' => 'active']);
            $hiace = $transport->saveVehicle(['vehicle_number' => 'LEA-5678', 'model' => 'Toyota Hiace', 'capacity' => 15, 'driver_name' => 'Rashid Ali', 'driver_phone' => '0321-7654321', 'driver_license' => 'LHR-55410', 'status' => 'active']);
            $transport->saveVehicle(['vehicle_number' => 'LEC-1357', 'model' => 'Suzuki Bolan', 'capacity' => 8, 'driver_name' => 'Nasir Mehmood', 'driver_phone' => '0345-2233445', 'driver_license' => 'LHR-40218', 'status' => 'inactive']);

            $this->routes['R1'] = $transport->saveRoute([
                'name' => 'Route 1 — Model Town', 'transport_vehicle_id' => $coaster->id, 'start_point' => 'Model Town', 'end_point' => 'School', 'fare' => 3500,
                'description' => 'Drops off from 1:30 p.m. in the reverse order.',
                'stops' => [['name' => 'Model Town Link Road', 'time' => '07:05'], ['name' => 'Faisal Town', 'time' => '07:15'], ['name' => 'Akbar Chowk', 'time' => '07:25'], ['name' => 'Township', 'time' => '07:35']],
            ]);

            $this->routes['R2'] = $transport->saveRoute([
                'name' => 'Route 2 — Johar Town', 'transport_vehicle_id' => $coaster2->id, 'start_point' => 'Johar Town', 'end_point' => 'School', 'fare' => 3000,
                'stops' => [['name' => 'Expo Centre', 'time' => '07:00'], ['name' => 'Shaukat Khanum', 'time' => '07:10'], ['name' => 'Wapda Town', 'time' => '07:20']],
            ]);

            $this->routes['R3'] = $transport->saveRoute([
                'name' => 'Route 3 — Gulberg & Garden Town', 'transport_vehicle_id' => $hiace->id, 'start_point' => 'Gulberg III', 'end_point' => 'School', 'fare' => 4000,
                'stops' => [['name' => 'Liberty Market', 'time' => '06:55'], ['name' => 'Main Boulevard Gulberg', 'time' => '07:05'], ['name' => 'Garden Town', 'time' => '07:15'], ['name' => 'Kalma Chowk', 'time' => '07:25']],
            ]);
        });
    }

    /**
     * Seats from the first day of term for families who asked for the bus.
     */
    private function bookBuses(): void
    {
        foreach ($this->pupils as $pupil) {
            $this->bookBus($pupil['student'], $this->yearStart);
        }
    }

    private function bookBus(StudentProfile $student, ?CarbonImmutable $from = null): void
    {
        $family = $this->families[$this->pupils[$student->id]['family']];
        $stop = $family['area'] ? DemoData::AREAS[$family['area']] : null;

        if (! $family['bus'] || ! $stop) {
            return;
        }

        try {
            $this->as($this->people['bilal'], fn () => app(Transport::class)->assign($student, $this->routes[$stop[0]], $stop[1], $this->date($from ?? tenant()->today()), $this->people['bilal']));
        } catch (ActionFailed) {
            // Bus full: the family is on the waiting list.
        }
    }

    private function planTransportChanges(): void
    {
        $officer = $this->people['bilal'];

        // A Johar Town family moves to Township: same school, Route 1 now.
        $moved = $this->on($this->schoolDay($this->today->subDays(15), back: true), '10:20');

        $this->at($moved, fn () => $this->as($officer, function () use ($officer) {
            $ride = StudentTransport::query()->active()->where('transport_route_id', $this->routes['R2']->id)->orderBy('id')->first();

            if ($ride) {
                app(Transport::class)->change($ride, $this->routes['R1'], 'Township', tenant()->today(), $officer);
            }
        }));

        // A family now drives their child in.
        $stopped = $this->on($this->schoolDay($this->today->subDays(30), back: true), '12:40');

        $this->at($stopped, fn () => $this->as($officer, function () use ($officer) {
            $ride = StudentTransport::query()->active()->where('transport_route_id', $this->routes['R3']->id)->orderByDesc('id')->first();

            if ($ride) {
                app(Transport::class)->end($ride, tenant()->today(), $officer);
            }
        }));
    }
}
