<?php

/*
 * The library's lending rules: loan period, renewals, books per borrower
 * and the fine for each day late. Stored in the school's settings.
 *     $dispatch('open-modal', { component: 'forms.library-settings' })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Services\Library\Library;

use function Livewire\Volt\{mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['loan_days' => '14', 'max_renewals' => '2', 'max_books_student' => '3', 'max_books_staff' => '5', 'fine_per_day' => '0']);

mount(function () {
    $this->authorize('library.manage');

    $this->fill(array_map(fn ($value) => (string) $value, Library::settings()));
});

rules([
    'loan_days' => ['required', 'integer', 'min:1', 'max:365'],
    'max_renewals' => ['required', 'integer', 'min:0', 'max:20'],
    'max_books_student' => ['required', 'integer', 'min:1', 'max:50'],
    'max_books_staff' => ['required', 'integer', 'min:1', 'max:50'],
    'fine_per_day' => ['required', 'numeric', 'min:0', 'max:100000'],
])->attributes([
    'loan_days' => __('loan period'),
    'max_renewals' => __('renewals'),
    'max_books_student' => __('books per student'),
    'max_books_staff' => __('books per staff member'),
    'fine_per_day' => __('fine per day'),
]);

$save = function () {
    $this->authorize('library.manage');
    $data = $this->validate();

    $school = tenant();
    $settings = $school->settings ?? [];
    $settings['library'] = [
        'loan_days' => (int) $data['loan_days'],
        'max_renewals' => (int) $data['max_renewals'],
        'max_books_student' => (int) $data['max_books_student'],
        'max_books_staff' => (int) $data['max_books_staff'],
        'fine_per_day' => round((float) $data['fine_per_day'], 2),
    ];
    $school->update(['settings' => $settings]);

    activity('settings')->performedOn($school)->causedBy(auth()->user())
        ->withProperties(['library' => $settings['library']])
        ->log('Library rules updated');

    $this->closeModal();
    $this->dispatch('library-updated');
    $this->toast(__('Library rules saved.'));
};

?>

<x-modal.form :title="__('Library rules')" :subtitle="__('Apply to books issued from now on.')">
    <div class="row">
        <div class="col-sm-6"><x-form.input name="loan_days" type="number" min="1" :label="__('Loan period (days)')" required /></div>
        <div class="col-sm-6"><x-form.input name="max_renewals" type="number" min="0" :label="__('Renewals allowed')" required :hint="__('Each renewal lends it for another loan period.')" /></div>
        <div class="col-sm-6"><x-form.input name="max_books_student" type="number" min="1" :label="__('Books per student')" required /></div>
        <div class="col-sm-6"><x-form.input name="max_books_staff" type="number" min="1" :label="__('Books per staff member')" required /></div>
    </div>
    <x-form.input name="fine_per_day" type="number" step="0.01" min="0" :label="__('Fine per day late')" required wrapper="mb-0"
                  :hint="__('In :currency. 0 for no overdue fines. Lost books are charged separately.', ['currency' => tenant()->setting('currency_code')])" />
</x-modal.form>
