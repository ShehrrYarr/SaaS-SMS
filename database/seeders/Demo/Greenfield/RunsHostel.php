<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\Gender;
use App\Models\HostelRoom;
use App\Models\StudentHostel;
use App\Services\Hostel\Hostels;
use App\Tables\ActionFailed;

/**
 * Boarding: a boys' and a girls' house with dormitories and twin rooms,
 * boarders from out of town checked in the day before term, one moved to a
 * quieter room, one now a day scholar.
 */
trait RunsHostel
{
    /** @var array<string, array<string, HostelRoom>> boys | girls => room number => room */
    private array $rooms = [];

    private function setUpHostel(): void
    {
        $this->as($this->admin, function () {
            $hostels = app(Hostels::class);

            $houses = [
                'boys' => $hostels->saveBuilding(['name' => 'Iqbal House', 'type' => 'boys', 'warden_staff_profile_id' => $this->staff['sajid']->id, 'address' => 'Block A, behind the main block', 'description' => 'Boys\' boarding house, Grades 5 to 8.']),
                'girls' => $hostels->saveBuilding(['name' => 'Fatima House', 'type' => 'girls', 'warden_staff_profile_id' => $this->staff['nadia']->id, 'address' => 'Block B, next to the science block', 'description' => 'Girls\' boarding house, Grades 5 to 8.']),
            ];

            $rooms = [
                ['boys', '101', 'Ground', 'Dormitory', 4, 15000], ['boys', '102', 'Ground', 'Dormitory', 4, 15000],
                ['boys', '201', 'First', 'Twin', 2, 18000], ['boys', '202', 'First', 'Twin', 2, 18000], ['boys', '203', 'First', 'Single', 1, 22000],
                ['girls', '101', 'Ground', 'Dormitory', 4, 15000], ['girls', '102', 'Ground', 'Twin', 2, 18000], ['girls', '201', 'First', 'Twin', 2, 18000],
            ];

            foreach ($rooms as [$house, $number, $floor, $kind, $beds, $fee]) {
                $this->rooms[$house][$number] = $hostels->saveRoom([
                    'hostel_building_id' => $houses[$house]->id, 'room_number' => $number, 'floor' => $floor,
                    'room_type' => $kind, 'capacity' => $beds, 'fee' => $fee, 'status' => 'active',
                ]);
            }
        });
    }

    /**
     * Boarders arrive the day before term, dormitories first.
     */
    private function allocateRooms(): void
    {
        $hostels = app(Hostels::class);

        foreach ($this->pupils as $pupil) {
            if (! $pupil['boarder']) {
                continue;
            }

            $house = $pupil['gender'] === Gender::Female ? 'girls' : 'boys';
            $warden = $this->people[$house === 'girls' ? 'nadia' : 'sajid'];

            foreach ($this->rooms[$house] as $room) {
                $bed = $hostels->freeBedNumbers($room)[0] ?? null;

                if ($bed === null) {
                    continue;
                }

                try {
                    $this->as($warden, fn () => $hostels->allocate($pupil['student'], $room, (string) $bed, $this->date($this->yearStart->subDay()), $warden));

                    break;
                } catch (ActionFailed) {
                    continue;
                }
            }
        }
    }

    private function planHostelChanges(): void
    {
        $warden = $this->people['sajid'];

        // A boy moves from a dormitory to the single room before exams.
        $this->at($this->on($this->schoolDay($this->today->subDays(20), back: true), '16:30'), fn () => $this->as($warden, function () use ($warden) {
            $stay = StudentHostel::query()->active()->whereIn('hostel_room_id', [$this->rooms['boys']['101']->id, $this->rooms['boys']['102']->id])->orderBy('id')->first();

            if ($stay && app(Hostels::class)->freeBeds($this->rooms['boys']['203']) > 0) {
                app(Hostels::class)->move($stay, $this->rooms['boys']['203'], '1', tenant()->today(), $warden);
            }
        }));

        // A girl becomes a day scholar at the end of last month.
        $lastMonth = $this->today->startOfMonth()->subDay();

        if ($lastMonth->gt($this->yearStart)) {
            $this->at($this->on($lastMonth, '17:00'), fn () => $this->as($this->people['nadia'], function () {
                $stay = StudentHostel::query()->active()->whereIn('hostel_room_id', collect($this->rooms['girls'])->pluck('id'))->orderByDesc('id')->first();

                if ($stay) {
                    app(Hostels::class)->checkOut($stay, tenant()->today(), $this->people['nadia']);
                }
            }));
        }
    }
}
