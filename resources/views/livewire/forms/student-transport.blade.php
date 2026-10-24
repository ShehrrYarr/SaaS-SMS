<?php

/*
 * Put a student on a bus route, or move them to another route or stop.
 *     $dispatch('open-modal', { component: 'forms.student-transport' })
 *     $dispatch('open-modal', { component: 'forms.student-transport', params: { route: 2 } })
 *     $dispatch('open-modal', { component: 'forms.student-transport', params: { student: 12 } })
 *     $dispatch('open-modal', { component: 'forms.student-transport', params: { assignment: 5 } })
 *
 * A student who already rides a route is moved rather than given a second one.
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Livewire\Concerns\PicksStudent;
use App\Models\StudentProfile;
use App\Models\StudentTransport;
use App\Models\TransportRoute;
use App\Services\Transport\Transport;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class, PicksStudent::class]);

state(['current' => null, 'fixedStudent' => false])->locked();

state(['transport_route_id' => '', 'stop' => '', 'from' => '']);

mount(function (?int $student = null, ?int $route = null, ?int $assignment = null) {
    $this->authorize('transport.manage');
    $this->from = tenant()->today()->toDateString();

    if ($assignment) {
        $record = StudentTransport::query()->active()->with('student')->findOrFail($assignment);
        $this->fixedStudent = true;
        $this->setPickedStudent($record->student);
        $this->studentPicked($record->student);

        return;
    }

    if ($student) {
        $this->fixedStudent = true;
        $profile = StudentProfile::query()->active()->findOrFail($student);
        $this->setPickedStudent($profile);
        $this->studentPicked($profile);
    }

    if ($route) {
        $this->transport_route_id = (string) TransportRoute::query()->findOrFail($route)->id;
    }
});

// Only current students can be given transport.
$pickableStudents = protect(fn (): Builder => StudentProfile::query()->active());

// A student who already rides a route: the form moves them.
$studentPicked = protect(function (StudentProfile $student) {
    $active = StudentTransport::query()->active()->where('student_profile_id', $student->id)->first();

    $this->current = $active?->id;

    if ($active) {
        $this->transport_route_id = (string) $active->transport_route_id;
        $this->stop = (string) $active->pickup_point;
    }
});

$currentAssignment = computed(fn () => $this->current ? StudentTransport::query()->with('route')->find($this->current) : null);

$routes = computed(fn () => TransportRoute::query()->with('vehicle')->withCount('activeAssignments')->orderBy('name')->get());

$chosenRoute = computed(fn () => $this->routes->firstWhere('id', (int) $this->transport_route_id));

$routeOptions = computed(fn () => $this->routes->mapWithKeys(function (TransportRoute $route) {
    $seats = $route->seats();
    $left = $seats === null ? null : max(0, $seats - $route->active_assignments_count + ($route->id === $this->currentAssignment?->transport_route_id ? 1 : 0));

    return [$route->id => $route->name.' · '.Money::school($route->fare).($left === null ? '' : ' · '.($left > 0 ? trans_choice(':count seat left|:count seats left', $left, ['count' => $left]) : __('full')))];
})->all());

$stopOptions = computed(fn () => $this->chosenRoute
    ? $this->chosenRoute->stopList()->mapWithKeys(fn (array $stop) => [$stop['name'] => $stop['name'].($stop['time'] ? ' · '.Carbon::parse($stop['time'])->format(tenant()->setting('time_format')) : '')])->all()
    : []);

updated(['transport_route_id' => function () {
    unset($this->chosenRoute, $this->stopOptions);

    if ($this->stopOptions !== [] && ! array_key_exists($this->stop, $this->stopOptions)) {
        $this->stop = '';
    }
}]);

rules(fn () => [
    'student_id' => ['required'],
    'transport_route_id' => ['required', Rule::in(array_map('strval', array_keys($this->routeOptions)))],
    'stop' => [$this->stopOptions === [] ? 'nullable' : 'required', 'string', 'max:100', ...($this->stopOptions === [] ? [] : [Rule::in(array_map('strval', array_keys($this->stopOptions)))])],
    'from' => ['required', 'date'],
])->attributes([
    'student_id' => __('student'),
    'transport_route_id' => __('route'),
    'from' => __('start date'),
]);

$save = function (Transport $transport) {
    $this->authorize('transport.manage');
    $data = $this->validate();

    $student = $this->pickedStudent() ?? abort(404);
    $route = TransportRoute::query()->findOrFail((int) $data['transport_route_id']);
    $from = Carbon::parse($data['from']);

    try {
        $this->current
            ? $transport->change(StudentTransport::query()->findOrFail($this->current), $route, $data['stop'], $from, auth()->user())
            : $transport->assign($student, $route, $data['stop'], $from, auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('transport_route_id', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('transport-updated');
    $this->toast($this->current
        ? __(':name now rides :route.', ['name' => $student->user?->name, 'route' => $route->name])
        : __(':name added to :route.', ['name' => $student->user?->name, 'route' => $route->name]));
};

?>

<x-modal.form :title="$current ? __('Change route or stop') : __('Give transport')" :submit-label="$current ? __('Save change') : __('Add to route')">
    <x-form.student-picker :results="$this->studentResults" :selected="$studentLabel" :picked="$student_id" :search="$studentSearch" :locked="$fixedStudent" />

    @if ($this->currentAssignment)
        <div class="alert alert-info py-2 fs-13">
            <i class="ri-bus-2-line me-1"></i>
            {{ __('Rides :route since :date.', ['route' => $this->currentAssignment->route?->name, 'date' => $this->currentAssignment->start_date->format(tenant()->setting('date_format'))]) }}
            {{ __('Changing the route ends that assignment the day before the new one starts.') }}
        </div>
    @endif

    <x-form.select name="transport_route_id" :label="__('Route')" :options="$this->routeOptions" required live />

    @if ($this->stopOptions !== [])
        <x-form.select name="stop" :label="__('Pickup stop')" :options="$this->stopOptions" required />
    @else
        <x-form.input name="stop" :label="__('Pickup point')" maxlength="100" :hint="__('This route has no stops listed.')" />
    @endif

    <x-form.input name="from" type="date" :label="$current ? __('Change from') : __('Starts on')" required wrapper="mb-0" />
</x-modal.form>
