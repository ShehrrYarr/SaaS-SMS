<?php

namespace App\Services\Hostel;

use App\Enums\AllocationStatus;
use App\Enums\HostelType;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\StudentHostel;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Attendance\SchoolCalendar;
use App\Services\Notifications\FamilyNotifier;
use App\Support\SelfService;
use App\Tables\ActionFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hostels: buildings, rooms (a bed per place) and the students staying in
 * them.
 *
 * A student has one active stay at a time, in a room with a free bed, in a
 * building that admits them (boys, girls or mixed). Moving rooms ends the
 * old stay the day before, so the history (and monthly billing) stays right.
 */
class Hostels
{
    public function __construct(private readonly SchoolCalendar $calendar, private readonly FamilyNotifier $family) {}

    /**
     * @param  array{name: string, type: string, warden_staff_profile_id?: int|string|null, address?: string|null, description?: string|null}  $data
     *
     * @throws ActionFailed
     */
    public function saveBuilding(array $data, ?HostelBuilding $building = null): HostelBuilding
    {
        $values = [
            'name' => trim($data['name']),
            'type' => HostelType::from($data['type']),
            'warden_staff_profile_id' => filled($data['warden_staff_profile_id'] ?? null) ? (int) $data['warden_staff_profile_id'] : null,
            'address' => self::clean($data['address'] ?? null),
            'description' => self::clean($data['description'] ?? null),
        ];

        if ($building) {
            if ($values['type'] !== $building->type) {
                $probe = (new HostelBuilding)->forceFill(['type' => $values['type']]);
                $misplaced = StudentHostel::query()->active()
                    ->whereHas('room', fn ($query) => $query->where('hostel_building_id', $building->id))
                    ->with('student')
                    ->get()
                    ->filter(fn (StudentHostel $stay) => ! $probe->admits($stay->student?->gender))
                    ->count();

                if ($misplaced > 0) {
                    throw new ActionFailed(trans_choice(':count resident wouldn\'t be admitted to a :type hostel. Move them out first.|:count residents wouldn\'t be admitted to a :type hostel. Move them out first.', $misplaced, ['count' => $misplaced, 'type' => mb_strtolower($values['type']->label())]));
                }
            }

            $building->update($values);

            return $building;
        }

        $removed = HostelBuilding::onlyTrashed()->where('name', $values['name'])->first();

        if ($removed) {
            $removed->restore();
            $removed->update($values);

            return $removed;
        }

        return HostelBuilding::query()->create($values);
    }

    /**
     * @throws ActionFailed
     */
    public function deleteBuilding(HostelBuilding $building): void
    {
        $residents = $this->residentsIn($building);

        if ($residents > 0) {
            throw new ActionFailed(trans_choice(':count student stays in :name. Check them out or move them first.|:count students stay in :name. Check them out or move them first.', $residents, ['count' => $residents, 'name' => $building->name]));
        }

        DB::transaction(function () use ($building) {
            $building->rooms()->get()->each->delete();
            $building->delete();
        });
    }

    /**
     * @param  array{hostel_building_id: int|string, room_number: string, floor?: string|null, room_type?: string|null, capacity: int|string, fee: float|string, status: string}  $data
     *
     * @throws ActionFailed
     */
    public function saveRoom(array $data, ?HostelRoom $room = null): HostelRoom
    {
        $values = [
            'hostel_building_id' => (int) $data['hostel_building_id'],
            'room_number' => trim($data['room_number']),
            'floor' => self::clean($data['floor'] ?? null),
            'room_type' => self::clean($data['room_type'] ?? null),
            'capacity' => (int) $data['capacity'],
            'fee' => round((float) $data['fee'], 2),
            'status' => $data['status'],
        ];

        if ($room) {
            $residents = $room->activeAllocations()->count();

            if ($values['capacity'] < $residents) {
                throw new ActionFailed(trans_choice(':count student stays in this room, so it needs at least :count bed.|:count students stay in this room, so it needs at least :count beds.', $residents, ['count' => $residents]));
            }

            if ($values['hostel_building_id'] !== $room->hostel_building_id && $residents > 0) {
                throw new ActionFailed(__('Students stay in this room. Move them before moving the room to another building.'));
            }

            $room->update($values);

            return $room;
        }

        $removed = HostelRoom::onlyTrashed()
            ->where('hostel_building_id', $values['hostel_building_id'])
            ->where('room_number', $values['room_number'])
            ->first();

        if ($removed) {
            $removed->restore();
            $removed->update($values);

            return $removed;
        }

        return HostelRoom::query()->create($values);
    }

    /**
     * @throws ActionFailed
     */
    public function deleteRoom(HostelRoom $room): void
    {
        $residents = $room->activeAllocations()->count();

        if ($residents > 0) {
            throw new ActionFailed(trans_choice(':count student stays in this room. Check them out or move them first.|:count students stay in this room. Check them out or move them first.', $residents, ['count' => $residents]));
        }

        $room->delete();
    }

    public function residentsIn(HostelBuilding $building): int
    {
        return StudentHostel::query()->active()
            ->whereHas('room', fn ($query) => $query->where('hostel_building_id', $building->id))
            ->count();
    }

    public function freeBeds(HostelRoom $room, ?int $ignoring = null): int
    {
        $taken = $room->activeAllocations()->when($ignoring, fn ($query) => $query->whereKeyNot($ignoring))->count();

        return max(0, $room->capacity - $taken);
    }

    /**
     * Bed labels in use in a room.
     *
     * @return Collection<int, string>
     */
    public function bedsTaken(HostelRoom $room, ?int $ignoring = null): Collection
    {
        return $room->activeAllocations()
            ->when($ignoring, fn ($query) => $query->whereKeyNot($ignoring))
            ->whereNotNull('bed_number')
            ->pluck('bed_number')
            ->map(fn ($bed) => (string) $bed);
    }

    /**
     * Bed numbers 1..capacity that nobody has.
     *
     * @return list<string>
     */
    public function freeBedNumbers(HostelRoom $room, ?int $ignoring = null): array
    {
        $taken = $this->bedsTaken($room, $ignoring)->map(fn ($bed) => mb_strtolower($bed))->all();

        return array_values(array_filter(
            array_map('strval', range(1, max(1, $room->capacity))),
            fn (string $bed) => ! in_array($bed, $taken, true),
        ));
    }

    /**
     * @throws ActionFailed
     */
    public function allocate(StudentProfile $student, HostelRoom $room, ?string $bed, CarbonInterface $checkIn, User $by): StudentHostel
    {
        if (! $student->isActive()) {
            throw new ActionFailed(__('Only a current student can be given a hostel room.'));
        }

        return DB::transaction(function () use ($student, $room, $bed, $checkIn, $by) {
            $current = StudentHostel::query()->active()->with('room.building')->where('student_profile_id', $student->id)->lockForUpdate()->first();

            if ($current) {
                throw new ActionFailed(__(':name already stays in :room. Move them instead.', ['name' => $student->user?->name, 'room' => $current->room?->label()]));
            }

            $room = HostelRoom::query()->with('building')->lockForUpdate()->findOrFail($room->id);
            $bed = $this->checkPlace($student, $room, $bed);

            $stay = StudentHostel::query()->create([
                'student_profile_id' => $student->id,
                'hostel_room_id' => $room->id,
                'academic_year_id' => $this->calendar->yearFor($checkIn)?->id,
                'bed_number' => $bed,
                'check_in' => $checkIn->toDateString(),
                'status' => AllocationStatus::Active,
            ]);

            $this->log($stay, $by, 'Hostel room allocated', ['student' => $student->user?->name, 'room' => $room->label(), 'bed' => $bed]);
            $this->announce($stay->setRelation('room', $room));

            return $stay;
        });
    }

    /**
     * Move a resident to another room (or bed) from a date. A move dated on
     * or before check-in, or within the same room, edits the stay; a later
     * move to another room ends it and starts a new one.
     *
     * @throws ActionFailed
     */
    public function move(StudentHostel $stay, HostelRoom $room, ?string $bed, CarbonInterface $from, User $by): StudentHostel
    {
        if (! $stay->isActive()) {
            throw new ActionFailed(__('This stay has ended. Allocate a room again instead.'));
        }

        return DB::transaction(function () use ($stay, $room, $bed, $from, $by) {
            $student = $stay->student()->with('user')->firstOrFail();
            $room = HostelRoom::query()->with('building')->lockForUpdate()->findOrFail($room->id);
            $bed = $this->checkPlace($student, $room, $bed, $stay->id);

            if ($room->id === $stay->hostel_room_id || $from->toDateString() <= $stay->check_in->toDateString()) {
                $stay->update(['hostel_room_id' => $room->id, 'bed_number' => $bed]
                    + ($room->id === $stay->hostel_room_id ? [] : ['check_in' => $from->toDateString()]));
                $moved = $stay;
            } else {
                $stay->update(['status' => AllocationStatus::Ended, 'check_out' => $from->copy()->subDay()->toDateString()]);

                $moved = StudentHostel::query()->create([
                    'student_profile_id' => $stay->student_profile_id,
                    'hostel_room_id' => $room->id,
                    'academic_year_id' => $this->calendar->yearFor($from)?->id,
                    'bed_number' => $bed,
                    'check_in' => $from->toDateString(),
                    'status' => AllocationStatus::Active,
                ]);
            }

            $this->log($moved, $by, 'Hostel room changed', ['student' => $student->user?->name, 'room' => $room->label(), 'bed' => $bed, 'from' => $from->toDateString()]);
            $this->announce($moved->setRelation('room', $room));

            return $moved;
        });
    }

    /**
     * The student leaves the hostel on $on.
     *
     * @throws ActionFailed
     */
    public function checkOut(StudentHostel $stay, CarbonInterface $on, ?User $by, bool $notify = true): void
    {
        if (! $stay->isActive()) {
            throw new ActionFailed(__('This student has already checked out.'));
        }

        if ($on->toDateString() < $stay->check_in->toDateString()) {
            throw new ActionFailed(__('That is before they checked in (:date).', ['date' => $stay->check_in->format(tenant()->setting('date_format'))]));
        }

        $stay->update(['status' => AllocationStatus::Ended, 'check_out' => $on->toDateString()]);
        $stay->loadMissing(['room.building', 'student.user']);

        $this->log($stay, $by, 'Checked out of hostel', ['student' => $stay->student?->user?->name, 'room' => $stay->room?->label(), 'on' => $on->toDateString()]);

        if ($notify) {
            $date = $on->format(tenant()->setting('date_format'));
            $this->family->notify($stay->student_profile_id, fn (StudentProfile $student, string $portal) => new SystemNotification(
                __(':name checked out of the hostel', ['name' => $student->user?->name]),
                __('Left :room on :date.', ['room' => $stay->room?->label(), 'date' => $date]),
                SelfService::url($portal, 'services', $student),
                'ri-hotel-bed-line',
                'secondary',
            ), student: false);
        }
    }

    /**
     * The room must be open, admit the student and have the bed free.
     * Returns the bed label to store.
     *
     * @throws ActionFailed
     */
    private function checkPlace(StudentProfile $student, HostelRoom $room, ?string $bed, ?int $ignoring = null): ?string
    {
        if (! $room->isActive()) {
            throw new ActionFailed(__(':room is closed.', ['room' => $room->label()]));
        }

        if ($room->building && ! $room->building->admits($student->gender)) {
            throw new ActionFailed(__(':building is a :type hostel.', ['building' => $room->building->name, 'type' => mb_strtolower($room->building->type->label())]));
        }

        if ($this->freeBeds($room, $ignoring) < 1) {
            throw new ActionFailed(__(':room is full.', ['room' => $room->label()]));
        }

        $bed = self::clean($bed);

        if ($bed !== null && $this->bedsTaken($room, $ignoring)->contains(fn ($taken) => mb_strtolower($taken) === mb_strtolower($bed))) {
            throw new ActionFailed(__('Bed :bed in :room is taken.', ['bed' => $bed, 'room' => $room->label()]));
        }

        return $bed;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(StudentHostel $stay, ?User $by, string $event, array $properties): void
    {
        activity('hostel')->performedOn($stay)->causedBy($by)->withProperties(array_filter($properties))->log($event);
    }

    private function announce(StudentHostel $stay): void
    {
        $room = $stay->room;

        $this->family->notify($stay->student_profile_id, fn (StudentProfile $student, string $portal) => new SystemNotification(
            __('Hostel room for :name: :room', ['name' => $student->user?->name, 'room' => $room?->label()]),
            $stay->bed_number ? __('Bed :bed, from :date.', ['bed' => $stay->bed_number, 'date' => $stay->check_in->format(tenant()->setting('date_format'))]) : null,
            SelfService::url($portal, 'services', $student),
            'ri-hotel-bed-line',
            'info',
        ), student: false);
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
