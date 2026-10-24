<?php

use App\Enums\AllocationStatus;
use App\Enums\StudentStatus;
use App\Enums\UserType;
use App\Models\AcademicYear;
use App\Models\Campus;
use App\Models\Invoice;
use App\Models\InvoiceBatch;
use App\Models\SchoolClass;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Services\Fees\Invoices;
use App\Services\Fees\ServiceCharges;
use App\Services\Schools\SeedSchoolDefaults;
use App\Services\Students\StudentRecords;
use App\Services\Transport\Transport;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->school = makeSchool(['slug' => 'alpha']);
    seedSchoolRoles($this->school);
    app(SeedSchoolDefaults::class)->handle($this->school);
    tenancy()->set($this->school);

    $this->admin = makeUserWithRole($this->school, UserType::SchoolAdmin, 'School Admin');
    $this->manager = makeUserWithRole($this->school, UserType::Staff, 'Transport Manager', ['name' => 'Bilal Transport']);
    makeStaffProfile($this->school, $this->manager);

    AcademicYear::factory()->current()->create(['start_date' => '2026-08-01', 'end_date' => '2027-06-30']);
    $campus = Campus::query()->create(['name' => 'Main', 'is_main' => true]);
    $this->class = SchoolClass::query()->create(['campus_id' => $campus->id, 'name' => 'Grade 6', 'numeric_level' => 6]);

    $records = app(StudentRecords::class);
    $this->asma = $records->admit(['name' => 'Asma Khan', 'class_id' => $this->class->id], [['name' => 'Kamran Khan', 'phone' => '0301', 'relation' => 'father']])->student;
    $this->bilal = $records->admit(['name' => 'Bilal Ahmed', 'class_id' => $this->class->id])->student;
    $this->chand = $records->admit(['name' => 'Chand Ali', 'class_id' => $this->class->id])->student;
    $this->parent = $this->asma->parents()->with('user')->first()->user;
    $this->parent->forceFill(['must_change_password' => false])->save();
    $this->asma->user->forceFill(['must_change_password' => false])->save();

    $this->transport = app(Transport::class);
    $this->van = $this->transport->saveVehicle(['vehicle_number' => 'lea-55', 'model' => 'Suzuki Bolan', 'capacity' => 2, 'driver_name' => 'Rashid', 'driver_phone' => '0321 7654321', 'status' => 'active']);
    $this->route = $this->transport->saveRoute(['name' => 'Route 1', 'transport_vehicle_id' => $this->van->id, 'fare' => 2500, 'stops' => [
        ['name' => 'Expo Centre', 'time' => '07:10'], ['name' => 'Wapda Town', 'time' => '07:30'],
    ]]);
    $this->other = $this->transport->saveRoute(['name' => 'Route 2', 'fare' => 3000, 'stops' => []]);

    Carbon::setTestNow('2026-10-20 09:00:00');
    $this->actingAs($this->manager);
});

afterEach(fn () => Carbon::setTestNow());

it('seats as many students as the vehicle holds, at a stop on the route', function () {
    expect($this->van->vehicle_number)->toBe('LEA-55');

    $this->transport->assign($this->asma, $this->route, 'expo centre', Carbon::parse('2026-10-01'), $this->manager);
    $this->transport->assign($this->bilal, $this->route, 'Wapda Town', Carbon::parse('2026-10-01'), $this->manager);

    expect(StudentTransport::query()->where('student_profile_id', $this->asma->id)->value('pickup_point'))->toBe('Expo Centre')
        ->and($this->transport->seatsLeft($this->route))->toBe(0)
        ->and(fn () => $this->transport->assign($this->chand, $this->route, 'Expo Centre', Carbon::parse('2026-10-01'), $this->manager))->toThrow(ActionFailed::class, 'full')
        ->and(fn () => $this->transport->assign($this->chand, $this->other, null, Carbon::parse('2026-10-01'), $this->manager))->not->toThrow(ActionFailed::class);

    // One route at a time; a stop must be on the route.
    expect(fn () => $this->transport->assign($this->asma, $this->other, null, Carbon::parse('2026-10-01'), $this->manager))->toThrow(ActionFailed::class, 'already rides');

    $this->transport->end(StudentTransport::query()->where('student_profile_id', $this->bilal->id)->sole(), Carbon::parse('2026-10-15'), $this->manager);
    expect(fn () => $this->transport->assign($this->bilal, $this->route, 'Nowhere', Carbon::parse('2026-10-20'), $this->manager))->toThrow(ActionFailed::class, 'Choose one of the stops');
});

it('protects riders when vehicles and stops change', function () {
    $this->transport->assign($this->asma, $this->route, 'Expo Centre', Carbon::parse('2026-10-01'), $this->manager);
    $this->transport->assign($this->bilal, $this->route, 'Wapda Town', Carbon::parse('2026-10-01'), $this->manager);

    expect(fn () => $this->transport->saveVehicle(['vehicle_number' => 'LEA-55', 'capacity' => 1, 'status' => 'active'], $this->van))->toThrow(ActionFailed::class, 'at least 2 seats')
        ->and(fn () => $this->transport->saveRoute(['name' => 'Route 1', 'transport_vehicle_id' => $this->van->id, 'fare' => 2500, 'stops' => [['name' => 'Expo Centre', 'time' => '07:10']]], $this->route))->toThrow(ActionFailed::class, 'Wapda Town')
        ->and(fn () => $this->transport->deleteRoute($this->route))->toThrow(ActionFailed::class);

    // Removing the vehicle leaves the route without one (and without a seat limit).
    $this->transport->deleteVehicle($this->van);
    expect($this->route->fresh()->transport_vehicle_id)->toBeNull()
        ->and($this->transport->seatsLeft($this->route->fresh()))->toBeNull();

    // Adding a removed vehicle's number brings it back.
    $again = $this->transport->saveVehicle(['vehicle_number' => 'LEA-55', 'capacity' => 10, 'status' => 'active']);
    expect($again->id)->toBe($this->van->id);
});

it('moves a student to another route from a date, keeping the history', function () {
    $first = $this->transport->assign($this->asma, $this->route, 'Expo Centre', Carbon::parse('2026-09-01'), $this->manager);

    // Another stop on the same route is just an edit.
    $this->transport->change($first, $this->route, 'Wapda Town', Carbon::parse('2026-10-10'), $this->manager);
    expect(StudentTransport::query()->count())->toBe(1)
        ->and($first->fresh()->pickup_point)->toBe('Wapda Town');

    $second = $this->transport->change($first->fresh(), $this->other, 'Garden Town', Carbon::parse('2026-10-10'), $this->manager);

    expect($first->fresh()->status)->toBe(AllocationStatus::Ended)
        ->and($first->fresh()->end_date->toDateString())->toBe('2026-10-09')
        ->and($second->transport_route_id)->toBe($this->other->id)
        ->and($second->pickup_point)->toBe('Garden Town');

    // The parent heard about each change.
    $titles = $this->parent->notifications()->get()->pluck('data.title');
    expect($titles)->toHaveCount(3)
        ->and($titles->filter(fn ($title) => str_contains($title, 'Route 2')))->toHaveCount(1);
});

it('bills the fare on monthly invoices once a month', function () {
    $this->transport->assign($this->asma, $this->route, 'Expo Centre', Carbon::parse('2026-10-05'), $this->manager);
    $charges = app(ServiceCharges::class);

    expect($charges->preview($this->asma, '2026-09'))->toBe([])
        ->and($charges->linesFor($this->asma, '2026-10', ['transport']))->toHaveCount(1);

    // Bulk generation with transport ticked and no fees.
    $this->actingAs($this->admin);
    Volt::test('forms.invoice-batch')
        ->set('billing_period', '2026-10')
        ->assertSet('services', ['transport', 'hostel'])
        ->set('fee_ids', [])
        ->call('save')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->with('items')->sole();
    expect($invoice->student_profile_id)->toBe($this->asma->id)
        ->and((float) $invoice->total_amount)->toBe(2500.0)
        ->and($invoice->items->sole()->description)->toBe('Transport: Route 1 — October 2026')
        ->and(InvoiceBatch::query()->sole()->skipped)->toBe(2);

    // Running it again bills nothing new; a single invoice shows it as billed.
    expect($charges->linesFor($this->asma, '2026-10', ['transport']))->toBe([]);

    Volt::test('forms.invoice', ['student' => $this->asma->id])
        ->set('billing_period', '2026-10')
        ->assertSet('services', ['transport' => false]);

    // November: offered and ticked.
    Volt::test('forms.invoice', ['student' => $this->asma->id])
        ->set('billing_period', '2026-11')
        ->assertSet('services', ['transport' => true])
        ->call('save')
        ->assertHasNoErrors();

    expect(Invoice::query()->where('billing_period', '2026-11')->sole()->total_amount)->toEqual('2500.00');

    // Cancelling the invoice lets it be billed again.
    app(Invoices::class)->cancel(Invoice::query()->where('billing_period', '2026-11')->sole(), 'Wrong', $this->admin);
    expect($charges->alreadyBilled($this->asma, 'transport', '2026-11'))->toBeFalse();
});

it('takes a student off transport when they leave the school', function () {
    $this->transport->assign($this->asma, $this->route, 'Expo Centre', Carbon::parse('2026-10-01'), $this->manager);

    app(StudentRecords::class)->changeStatus($this->asma, StudentStatus::Left, Carbon::parse('2026-10-18'), null, $this->admin);

    $assignment = StudentTransport::query()->sole();
    expect($assignment->status)->toBe(AllocationStatus::Ended)
        ->and($assignment->end_date->toDateString())->toBe('2026-10-18')
        ->and($this->transport->seatsLeft($this->route))->toBe(2);
});

it('manages routes, vehicles and riders from the transport screens', function () {
    $this->get(route('admin.transport.index'))->assertOk()->assertSee('Route 1');
    $this->get(route('admin.transport.index', ['tab' => 'vehicles']))->assertOk()->assertSee('LEA-55');

    Volt::test('forms.transport-route')
        ->set('name', 'Route 3')
        ->set('fare', '1800')
        ->set('stops', [['name' => 'Township', 'time' => '07:00'], ['name' => 'township', 'time' => '07:05']])
        ->call('save')
        ->assertHasErrors('stops.1.name')
        ->set('stops', [['name' => 'Township', 'time' => '07:00'], ['name' => 'Kalma Chowk', 'time' => '']])
        ->call('moveStop', 1, -1)
        ->call('save')
        ->assertHasNoErrors();

    expect(TransportRoute::query()->where('name', 'Route 3')->sole()->stopList()->pluck('name')->all())->toBe(['Kalma Chowk', 'Township']);

    Volt::test('forms.student-transport', ['route' => $this->route->id])
        ->call('pickStudent', $this->asma->id)
        ->set('stop', 'Wapda Town')
        ->call('save')
        ->assertHasNoErrors();

    $assignment = StudentTransport::query()->sole();
    $this->get(route('admin.transport.routes.show', $this->route->id))->assertOk()->assertSee('Asma Khan')->assertSee('07:30');

    // Picking a student who already rides moves them instead.
    Volt::test('forms.student-transport', ['student' => $this->asma->id])
        ->assertSet('current', $assignment->id)
        ->assertSet('transport_route_id', (string) $this->route->id);

    Volt::test('forms.end-service', ['kind' => 'transport', 'id' => $assignment->id])
        ->assertSet('date', '2026-10-20')
        ->set('date', '2026-10-01')
        ->call('save')
        ->assertHasErrors('date')
        ->set('date', '2026-10-22')
        ->call('save')
        ->assertHasNoErrors();

    expect($assignment->fresh()->status)->toBe(AllocationStatus::Ended);

    // Families see the bus; a teacher can't manage transport.
    $this->transport->assign($this->asma, $this->route, 'Expo Centre', Carbon::parse('2026-10-23'), $this->manager);
    $this->actingAs($this->parent)->get(route('parent.services'))->assertOk()->assertSee('Route 1')->assertSee('Rashid')->assertSee('07:10');
    $this->actingAs($this->parent)->get(route('parent.dashboard'))->assertOk()->assertSee('Expo Centre');

    $teacher = makeUserWithRole($this->school, UserType::Teacher, 'Teacher');
    $this->actingAs($teacher)->get(route('admin.transport.index'))->assertForbidden();
});
