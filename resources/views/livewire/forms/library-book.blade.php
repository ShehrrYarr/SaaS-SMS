<?php

/*
 * Add a book to the library catalogue, or edit one.
 *     $dispatch('open-modal', { component: 'forms.library-book' })
 *     $dispatch('open-modal', { component: 'forms.library-book', params: { book: 3 } })
 */

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\LibraryBook;
use App\Services\Library\Library;
use App\Tables\ActionFailed;

use function Livewire\Volt\{computed, mount, rules, state, uses};

uses([InteractsWithUi::class]);

state(['book' => null, 'out' => 0])->locked();

state(['title' => '', 'author' => '', 'isbn' => '', 'publisher' => '', 'category' => '', 'edition' => '', 'rack_number' => '', 'quantity' => '1', 'price' => '']);

mount(function (?int $book = null) {
    $this->authorize('library.manage');

    if ($book) {
        $record = LibraryBook::query()->findOrFail($book);

        $this->book = $record->id;
        $this->out = $record->copiesOut();
        $this->fill([
            ...array_map(fn ($value) => (string) $value, $record->only(['title', 'author', 'isbn', 'publisher', 'category', 'edition', 'rack_number'])),
            'quantity' => (string) $record->quantity,
            'price' => $record->price === null ? '' : (string) (float) $record->price,
        ]);
    }
});

// Categories already in use, offered as suggestions.
$categories = computed(fn () => LibraryBook::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all());

rules(fn () => [
    'title' => ['required', 'string', 'max:255'],
    'author' => ['nullable', 'string', 'max:255'],
    'isbn' => ['nullable', 'string', 'max:20', 'regex:/^[0-9Xx -]+$/'],
    'publisher' => ['nullable', 'string', 'max:255'],
    'category' => ['nullable', 'string', 'max:100'],
    'edition' => ['nullable', 'string', 'max:50'],
    'rack_number' => ['nullable', 'string', 'max:30'],
    'quantity' => ['required', 'integer', 'min:'.max(1, $this->out), 'max:10000'],
    'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
])->attributes([
    'isbn' => 'ISBN',
    'rack_number' => __('rack'),
    'quantity' => __('copies'),
])->messages([
    'isbn.regex' => __('Use digits, X, spaces or dashes.'),
    'quantity.min' => __('There must be at least :min, including the copies out on loan.'),
]);

$save = function (Library $library) {
    $this->authorize('library.manage');
    $data = $this->validate();

    try {
        $record = $library->saveBook($data, $this->book ? LibraryBook::query()->findOrFail($this->book) : null);
    } catch (ActionFailed $e) {
        $this->addError('quantity', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('library-updated');
    $this->toast($this->book ? __('Book updated.') : __(':title added to the catalogue.', ['title' => $record->title]));
};

?>

<x-modal.form :title="$book ? __('Edit book') : __('Add a book')">
    <x-form.input name="title" :label="__('Title')" required maxlength="255" />
    <div class="row">
        <div class="col-sm-7"><x-form.input name="author" :label="__('Author')" maxlength="255" /></div>
        <div class="col-sm-5"><x-form.input name="isbn" label="ISBN" maxlength="20" inputmode="numeric" /></div>
        <div class="col-sm-7"><x-form.input name="publisher" :label="__('Publisher')" maxlength="255" /></div>
        <div class="col-sm-5"><x-form.input name="edition" :label="__('Edition')" maxlength="50" :placeholder="__('e.g. 3rd')" /></div>
        <div class="col-sm-7">
            <x-form.input name="category" :label="__('Category')" maxlength="100" list="library-categories" :placeholder="__('e.g. Fiction, Science')" />
            <datalist id="library-categories">
                @foreach ($this->categories as $category)
                    <option value="{{ $category }}"></option>
                @endforeach
            </datalist>
        </div>
        <div class="col-sm-5"><x-form.input name="rack_number" :label="__('Rack')" maxlength="30" :placeholder="__('e.g. B-3')" /></div>
        <div class="col-sm-6">
            <x-form.input name="quantity" type="number" min="1" :label="__('Copies')" required
                          :hint="$out > 0 ? trans_choice(':count copy is out on loan.|:count copies are out on loan.', $out, ['count' => $out]) : null" />
        </div>
        <div class="col-sm-6"><x-form.input name="price" type="number" step="0.01" min="0" :label="__('Price per copy')" :hint="__('Suggested as the fine if a copy is lost.')" /></div>
    </div>
</x-modal.form>
