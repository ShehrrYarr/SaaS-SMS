<?php

/*
 * Take a book back, or record that it was lost, with the fine owed.
 *     $dispatch('open-modal', { component: 'forms.book-return', params: { issue: 7 } })
 *     $dispatch('open-modal', { component: 'forms.book-return', params: { issue: 7, lost: true } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\BookIssue;
use App\Services\Library\Library;
use App\Support\Billing\Money;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class]);

state(['issue' => null, 'lost' => false])->locked();

state(['date' => '', 'fine' => '0', 'remarks' => '']);

mount(function (int $issue, bool $lost = false) {
    $this->authorize('library.issue');

    $record = BookIssue::query()->out()->findOrFail($issue);

    $this->issue = $record->id;
    $this->lost = $lost;
    $this->date = tenant()->today()->toDateString();
    $this->suggest();
});

$loan = computed(fn () => BookIssue::query()
    ->with(['book' => fn ($query) => $query->withTrashed(), 'borrower.studentProfile.schoolClass', 'borrower.studentProfile.section', 'borrower.staffProfile'])
    ->findOrFail($this->issue));

// The overdue fine up to the date, plus the book's price when it was lost.
$suggested = computed(function () {
    $on = strtotime($this->date) ? Carbon::parse($this->date) : tenant()->today();
    $overdue = Library::fineFor($this->loan, $on);

    return [
        'overdue' => $overdue,
        'days' => max(0, App\Models\BookIssue::daysBetween($this->loan->due_on, $on)),
        'price' => $this->lost ? (float) ($this->loan->book?->price ?? 0) : 0.0,
    ];
});

$suggest = protect(function () {
    unset($this->suggested);
    $this->fine = (string) round($this->suggested['overdue'] + $this->suggested['price'], 2);
});

updated(['date' => fn () => $this->suggest()]);

rules(fn () => [
    'date' => ['required', 'date', 'after_or_equal:'.$this->loan->issued_on->toDateString(), 'before_or_equal:'.tenant()->today()->toDateString()],
    'fine' => ['required', 'numeric', 'min:0', 'max:99999999'],
    'remarks' => ['nullable', 'string', 'max:255'],
])->attributes([
    'date' => __('date'),
]);

$save = function (Library $library) {
    $this->authorize('library.issue');
    $data = $this->validate();
    $loan = $this->loan;

    try {
        $this->lost
            ? $library->markLost($loan, Carbon::parse($data['date']), (float) $data['fine'], $data['remarks'], auth()->user())
            : $library->receive($loan, Carbon::parse($data['date']), (float) $data['fine'], $data['remarks'], auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('date', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('library-updated');

    $fine = (float) $data['fine'] > 0 ? ' '.__('Fine of :amount recorded.', ['amount' => Money::school($data['fine'])]) : '';
    $this->toast(($this->lost ? __(':title recorded as lost.', ['title' => $loan->book?->title]) : __(':title returned.', ['title' => $loan->book?->title])).$fine);
};

?>

@php
    $loan = $this->loan;
    $format = tenant()->setting('date_format');
    $suggested = $this->suggested;
    $perDay = Library::settings()['fine_per_day'];
@endphp

<x-modal.form :title="$lost ? __('Report a lost book') : __('Return a book')" :submit-label="$lost ? __('Record as lost') : __('Return')"
              :submit-color="$lost ? 'danger' : 'success'">
    <div class="border rounded p-3 mb-4">
        <div class="fw-medium">{{ $loan->book?->title }}</div>
        <div class="text-muted fs-13">{{ $loan->borrower?->name }}</div>
        <div class="d-flex flex-wrap gap-3 fs-13 mt-2">
            <span><i class="ri-arrow-right-up-line me-1 text-muted"></i>{{ __('Issued :date', ['date' => $loan->issued_on->format($format)]) }}</span>
            <span @class(['text-danger' => $loan->isOverdue()])><i class="ri-calendar-line me-1 text-muted"></i>{{ __('Due :date', ['date' => $loan->due_on->format($format)]) }}</span>
        </div>
    </div>

    <div class="row">
        <div class="col-sm-6">
            <x-form.input name="date" type="date" :label="$lost ? __('Reported lost on') : __('Returned on')" required live :max="tenant()->today()->toDateString()" />
        </div>
        <div class="col-sm-6">
            <x-form.input name="fine" type="number" step="0.01" min="0" :label="__('Fine')" required
                          :hint="collect([
                              $suggested['days'] > 0 && $perDay > 0 ? trans_choice(':count day late × :rate|:count days late × :rate', $suggested['days'], ['count' => $suggested['days'], 'rate' => Money::school($perDay)]) : null,
                              $lost && $suggested['price'] > 0 ? __('book price :amount', ['amount' => Money::school($suggested['price'])]) : null,
                          ])->filter()->implode(' + ') ?: __('Nothing suggested; enter an amount if one is owed.')" />
        </div>
    </div>

    @if ($lost)
        <p class="text-muted fs-13">{{ __('The copy is taken off the catalogue. If it turns up later, add it back by editing the book.') }}</p>
    @endif

    <x-form.input name="remarks" :label="__('Remarks')" maxlength="255" wrapper="mb-0" :placeholder="$lost ? __('e.g. Lost on the school trip') : __('e.g. Returned with a damaged spine')" />
</x-modal.form>
