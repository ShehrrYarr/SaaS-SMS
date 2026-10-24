<?php

use App\Enums\AllocationStatus;
use App\Enums\StudentStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\HostelRoom;
use App\Models\SchoolClass;
use App\Models\StudentHostel;
use App\Services\Fees\ServiceCharges;
use App\Services\Hostel\Hostels;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->warden = makeUserWithRole($this->school, UserType::Staff, 'Hostel Warden', ['name' => 'Nadia Warden', 'phone' => '0300-1112223']);
    $this->wardenProfile = makeStaffProfile($this->school, $this->warden);

    AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 9', 'numeric_level' => 9]);

    $records = app(StudentRecords::class);
    $this->ali = $records->admit(['name' => 'Ali Raza', 'gender' => 'male', 'class_id' => $class->id], [['name' => 'Raza Ahmed', 'phone' => '0301', 'relation' => 'father']])->student;
    $this->omar = $records->admit(['name' => 'Omar Farooq', 'gender' => 'male', 'class_id' => $class->id])->student;
    $this->sara = $records->admit(['name' => 'Sara Malik', 'gender' => 'female', 'class_id' => $class->id])->student;
    $this->parent = $this->ali->parents()->with('user')->first()->user;
    $this->parent->forceFill(['must_change_password' => false])->save();

    $this->hostels = app(Hostels::class);
    $this->boys = $this->hostels->saveBuilding(['name' => 'Iqbal House', 'type' => 'boys', 'warden_staff_profile_id' => $this->wardenProfile->id]);
    $this->twin = $this->hostels->saveRoom(['hostel_building_id' => $this->boys->id, 'room_number' => '101', 'capacity' => 2, 'fee' => 8000, 'status' => 'active']);
    $this->single = $this->hostels->saveRoom(['hostel_building_id' => $this->boys->id, 'room_number' => '102', 'capacity' => 1, 'fee' => 10000, 'status' => 'active']);

    Carbon::setTestNow('2026-10-20 09:00:00');
    $this->actingAs($this->warden);
});

afterEach(fn () => Carbon::setTestNow());

it('allocates beds within capacity, to students the building admits', function () {
    $this->hostels->allocate($this->ali, $this->twin, '1', Carbon::parse('2026-10-01'), $this->warden);

    expect(fn () => $this->hostels->allocate($this->omar, $this->twin, '1', Carbon::parse('2026-10-01'), $this->warden))->toThrow(ActionFailed::class, 'Bed 1')
        ->and(fn () => $this->hostels->allocate($this->sara, $this->twin, '2', Carbon::parse('2026-10-01'), $this->warden))->toThrow(ActionFailed::class, 'boys hostel')
        ->and(fn () => $this->hostels->allocate($this->ali, $this->single, null, Carbon::parse('2026-10-01'), $this->warden))->toThrow(ActionFailed::class, 'already stays');

    $this->hostels->allocate($this->omar, $this->twin, '2', Carbon::parse('2026-10-01'), $this->warden);

    expect($this->hostels->freeBeds($this->twin))->toBe(0)
        ->and(fn () => $this->hostels->allocate($this->sara, $this->single, null, Carbon::parse('2026-10-01'), $this->warden))->toThrow(ActionFailed::class)
        ->and(fn () => $this->hostels->saveRoom(['hostel_building_id' => $this->boys->id, 'room_number' => '101', 'capacity' => 1, 'fee' => 8000, 'status' => 'active'], $this->twin))->toThrow(ActionFailed::class, 'at least 2 beds')
        ->and(fn () => $this->hostels->deleteRoom($this->twin))->toThrow(ActionFailed::class)
        ->and(fn () => $this->hostels->saveBuilding(['name' => 'Iqbal House', 'type' => 'girls'], $this->boys))->toThrow(ActionFailed::class, 'girls hostel');

    // A mixed building takes anyone.
    $this->hostels->saveBuilding(['name' => 'Iqbal House', 'type' => 'mixed'], $this->boys);
    $this->hostels->allocate($this->sara, $this->single, null, Carbon::parse('2026-10-01'), $this->warden);
    expect(StudentHostel::query()->active()->count())->toBe(3);
});

it('moves residents between rooms and checks them out, keeping the history', function () {
    $stay = $this->hostels->allocate($this->ali, $this->twin, '1', Carbon::parse('2026-09-01'), $this->warden);

    // Another bed in the same room is an edit.
    $this->hostels->move($stay, $this->twin, '2', Carbon::parse('2026-10-10'), $this->warden);
    expect(StudentHostel::query()->count())->toBe(1)->and($stay->fresh()->bed_number)->toBe('2');

    $moved = $this->hostels->move($stay->fresh(), $this->single, null, Carbon::parse('2026-10-10'), $this->warden);

    expect($stay->fresh()->status)->toBe(AllocationStatus::Ended)
        ->and($stay->fresh()->check_out->toDateString())->toBe('2026-10-09')
        ->and($moved->hostel_room_id)->toBe($this->single->id);

    expect(fn () => $this->hostels->checkOut($moved, Carbon::parse('2026-10-01'), $this->warden))->toThrow(ActionFailed::class);
    $this->hostels->checkOut($moved, Carbon::parse('2026-10-20'), $this->warden);

    expect($moved->fresh()->status)->toBe(AllocationStatus::Ended)
        ->and($this->hostels->freeBeds($this->single))->toBe(1)
        ->and($this->parent->notifications()->get()->pluck('data.title')->filter(fn ($title) => str_contains($title, 'checked out')))->toHaveCount(1);
});

it('bills the room fee monthly and ends the stay when a student leaves', function () {
    $this->hostels->allocate($this->ali, $this->twin, '1', Carbon::parse('2026-10-01'), $this->warden);
    $charges = app(ServiceCharges::class);

    $lines = $charges->linesFor($this->ali, '2026-10', ['hostel']);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['net_amount'])->toBe(8000.0)
        ->and($lines[0]['description'])->toBe('Hostel: Room 101 · Iqbal House — October 2026');

    app(StudentRecords::class)->changeStatus($this->ali, StudentStatus::Graduated, Carbon::parse('2026-10-25'), null, $this->admin);

    $stay = StudentHostel::query()->sole();
    expect($stay->status)->toBe(AllocationStatus::Ended)
        ->and($stay->check_out->toDateString())->toBe('2026-10-25')
        // Still billed for October, not for November.
        ->and($charges->linesFor($this->ali, '2026-10', ['hostel']))->toHaveCount(1)
        ->and($charges->linesFor($this->ali, '2026-11', ['hostel']))->toBe([]);
});

it('runs the hostel from its screens', function () {
    $this->get(route('admin.hostel.index'))->assertOk()->assertSee('Iqbal House')->assertSee('Room 101');

    Volt::test('forms.hostel-room', ['building' => $this->boys->id])
        ->set('room_number', '201')
        ->set('capacity', '3')
        ->set('fee', '7000')
        ->call('save')
        ->assertHasNoErrors();

    $room = HostelRoom::query()->where('room_number', '201')->sole();

    // Picking a girl offers no boys' building.
    Volt::test('forms.student-hostel')
        ->call('pickStudent', $this->sara->id)
        ->assertSet('building', '');

    Volt::test('forms.student-hostel', ['room' => $room->id])
        ->call('pickStudent', $this->ali->id)
        ->assertSet('building', (string) $this->boys->id)
        ->set('hostel_room_id', (string) $room->id)
        ->assertSet('bed_number', '1')
        ->call('save')
        ->assertHasNoErrors();

    $stay = StudentHostel::query()->sole();

    Volt::test('forms.student-hostel', ['stay' => $stay->id])
        ->assertSet('current', $stay->id)
        ->assertSet('hostel_room_id', (string) $room->id);

    Volt::test('forms.end-service', ['kind' => 'hostel', 'id' => $stay->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($stay->fresh()->status)->toBe(AllocationStatus::Ended);

    // The family sees the room and warden.
    $this->hostels->allocate($this->ali, $this->twin, '2', Carbon::parse('2026-10-21'), $this->warden);
    $this->actingAs($this->parent)->get(route('parent.services'))
        ->assertOk()->assertSee('Iqbal House')->assertSee('Room 101')->assertSee('Nadia Warden')->assertSee('0300-1112223');

    // The student's profile lists the stay for whoever runs the hostel.
    $this->actingAs($this->admin);
    Volt::test('tenant.admin.students.show', ['student' => $this->ali->id])
        ->set('tab', 'services')
        ->assertSee('Transport, hostel & library')
        ->assertSee('Room 101')
        ->assertSee('Allocate or move room');

    // The warden can't touch transport or the library.
    $this->actingAs($this->warden)->get(route('admin.transport.index'))->assertForbidden();
});
