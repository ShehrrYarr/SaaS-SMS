<?php

/*
 * End a student's school transport, or check them out of the hostel.
 *     $dispatch('open-modal', { component: 'forms.end-service', params: { kind: 'transport', id: 5 } })
 *     $dispatch('open-modal', { component: 'forms.end-service', params: { kind: 'hostel', id: 3 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\StudentHostel;
use App\Models\StudentTransport;
use App\Services\Hostel\Hostels;
use App\Services\Transport\Transport;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['kind' => 'transport', 'id' => null])->locked();

state(['date' => '']);

mount(function (string $kind, int $id) {
    abort_unless(in_array($kind, ['transport', 'hostel'], true), 404);
    $this->authorize($kind.'.manage');

    $this->kind = $kind;
    $this->id = ($kind === 'transport' ? StudentTransport::query() : StudentHostel::query())->active()->findOrFail($id)->id;
    $this->date = tenant()->today()->toDateString();
});

$record = computed(fn () => $this->kind === 'transport'
    ? StudentTransport::query()->with(['student.user', 'route'])->findOrFail($this->id)
    : StudentHostel::query()->with(['student.user', 'room.building'])->findOrFail($this->id));

rules(fn () => [
    'date' => ['required', 'date', 'after_or_equal:'.($this->kind === 'transport' ? $this->record->start_date : $this->record->check_in)->toDateString()],
])->attributes(['date' => __('date')]);

$save = function (Transport $transport, Hostels $hostels) {
    $this->authorize($this->kind.'.manage');
    $data = $this->validate();
    $record = $this->record;

    try {
        $this->kind === 'transport'
            ? $transport->end($record, Carbon::parse($data['date']), auth()->user())
            : $hostels->checkOut($record, Carbon::parse($data['date']), auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('date', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch($this->kind.'-updated');
    $this->toast($this->kind === 'transport'
        ? __(':name is off :route.', ['name' => $record->student?->user?->name, 'route' => $record->route?->name])
        : __(':name checked out.', ['name' => $record->student?->user?->name]));
};

?>

@php($record = $this->record)

<x-modal.form :title="$kind === 'transport' ? __('End school transport') : __('Check out of the hostel')"
              :subtitle="$record->student?->user?->name.' · '.($kind === 'transport' ? $record->route?->name : $record->room?->label())"
              :submit-label="$kind === 'transport' ? __('End transport') : __('Check out')" submit-color="danger">
    <x-form.input name="date" type="date" :label="$kind === 'transport' ? __('Last day on the bus') : __('Checked out on')" required wrapper="mb-3" />
    <p class="text-muted fs-13 mb-0">
        {{ $kind === 'transport'
            ? __('They are still billed for the month this falls in. Their seat is free from the next day.')
            : __('They are still billed for the month this falls in. Their bed is free straight away.') }}
    </p>
</x-modal.form>
