<?php

/*
 * Lend a book to a student or a member of staff.
 *     $dispatch('open-modal', { component: 'forms.book-issue' })
 *     $dispatch('open-modal', { component: 'forms.book-issue', params: { book: 3 } })
 *     $dispatch('open-modal', { component: 'forms.book-issue', params: { borrower: 41 } })   // user id
 */

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\BookIssue;
use App\Models\LibraryBook;
use App\Models\User;
use App\Services\Library\Library;
use App\Tables\ActionFailed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

use function Livewire\Volt\{computed, mount, protect, rules, state, updated, uses};

uses([InteractsWithUi::class]);

state(['book_id' => '', 'bookLabel' => '', 'borrower_id' => '', 'borrowerLabel' => '', 'fixedBook' => false, 'fixedBorrower' => false])->locked();

state(['bookSearch' => '', 'borrowerSearch' => '', 'issued_on' => '', 'due_on' => '', 'remarks' => '']);

mount(function (?int $book = null, ?int $borrower = null) {
    $this->authorize('library.issue');

    $today = tenant()->today();
    $this->issued_on = $today->toDateString();
    $this->due_on = $today->copy()->addDays(Library::settings()['loan_days'])->toDateString();

    if ($book) {
        $this->pickBook($book);
        $this->fixedBook = true;
    }

    if ($borrower) {
        $this->pickBorrower($borrower);
        $this->fixedBorrower = true;
    }
});

$like = protect(fn (string $term) => '%'.addcslashes(trim($term), '%_\\').'%');

$bookResults = computed(fn () => mb_strlen(trim($this->bookSearch)) < 2 ? collect() : LibraryBook::query()
    ->where(fn (Builder $query) => $query->where('title', 'like', $this->like($this->bookSearch))
        ->orWhere('author', 'like', $this->like($this->bookSearch))
        ->orWhere('isbn', 'like', $this->like($this->bookSearch)))
    ->orderByDesc('available_quantity')->orderBy('title')
    ->limit(8)
    ->get());

// Students and staff with an active account (parents don't borrow).
$borrowers = protect(fn () => User::query()
    ->where('status', UserStatus::Active)
    ->whereNotIn('user_type', [UserType::Parent, UserType::SuperAdmin])
    ->with(['studentProfile.schoolClass', 'studentProfile.section', 'staffProfile']));

$borrowerResults = computed(function () {
    if (mb_strlen(trim($this->borrowerSearch)) < 2) {
        return collect();
    }

    $term = $this->like($this->borrowerSearch);

    return $this->borrowers()
        ->where(fn (Builder $query) => $query->where('name', 'like', $term)
            ->orWhere('username', 'like', $term)
            ->orWhere('phone', 'like', $term)
            ->orWhereHas('studentProfile', fn (Builder $student) => $student->where('admission_number', 'like', $term))
            ->orWhereHas('staffProfile', fn (Builder $staff) => $staff->where('employee_id', 'like', $term)))
        ->orderBy('name')
        ->limit(8)
        ->get();
});

$borrower = computed(fn () => $this->borrower_id === '' ? null : $this->borrowers()->find((int) $this->borrower_id));

// What the picked borrower has out now.
$loans = computed(fn () => $this->borrower
    ? BookIssue::query()->out()->with(['book' => fn ($query) => $query->withTrashed()])->where('user_id', $this->borrower->id)->orderBy('due_on')->get()
    : collect());

$pickBook = function (int $id) {
    $book = LibraryBook::query()->findOrFail($id);

    $this->book_id = (string) $book->id;
    $this->bookLabel = $book->label();
    $this->bookSearch = '';
    $this->resetErrorBag('book_id');
};

$clearBook = function () {
    $this->book_id = '';
    $this->bookLabel = '';
};

$pickBorrower = function (int $id) {
    $user = $this->borrowers()->findOrFail($id);

    $this->borrower_id = (string) $user->id;
    $this->borrowerLabel = $user->name.' · '.($user->studentProfile
        ? collect([$user->studentProfile->admission_number, $user->studentProfile->placementLabel()])->filter()->implode(' · ')
        : collect([__('Staff'), $user->staffProfile?->designation])->filter()->implode(' · '));
    $this->borrowerSearch = '';
    $this->resetErrorBag('borrower_id');
    unset($this->borrower, $this->loans);
};

$clearBorrower = function () {
    $this->borrower_id = '';
    $this->borrowerLabel = '';
};

// A new issue date moves the due date with it.
updated(['issued_on' => function () {
    if (strtotime($this->issued_on)) {
        $this->due_on = Carbon::parse($this->issued_on)->addDays(Library::settings()['loan_days'])->toDateString();
    }
}]);

rules(fn () => [
    'book_id' => ['required'],
    'borrower_id' => ['required'],
    'issued_on' => ['required', 'date', 'before_or_equal:'.tenant()->today()->toDateString()],
    'due_on' => ['required', 'date', 'after_or_equal:issued_on'],
    'remarks' => ['nullable', 'string', 'max:255'],
])->attributes([
    'book_id' => __('book'),
    'borrower_id' => __('borrower'),
    'issued_on' => __('issue date'),
    'due_on' => __('due date'),
]);

$save = function (Library $library) {
    $this->authorize('library.issue');
    $data = $this->validate();

    $book = LibraryBook::query()->findOrFail((int) $data['book_id']);
    $borrower = $this->borrower ?? abort(404);

    try {
        $library->issue($book, $borrower, Carbon::parse($data['issued_on']), Carbon::parse($data['due_on']), $data['remarks'], auth()->user());
    } catch (ActionFailed $e) {
        $this->addError('borrower_id', $e->getMessage());

        return;
    }

    $this->closeModal();
    $this->refreshTables();
    $this->dispatch('library-updated');
    $this->toast(__(':title issued to :name.', ['title' => $book->title, 'name' => $borrower->name]));
};

?>

@php($format = tenant()->setting('date_format'))

<x-modal.form :title="__('Issue a book')" :submit-label="__('Issue')">
    {{-- Book --}}
    <div class="mb-4 position-relative">
        <label class="form-label" for="field-book-search">{{ __('Book') }}<span class="text-danger ms-1">*</span></label>
        @if ($book_id !== '' && $bookSearch === '')
            <div class="input-group">
                <span @class(['form-control bg-light text-truncate', 'is-invalid' => $errors->has('book_id')])>{{ $bookLabel }}</span>
                @unless ($fixedBook)
                    <button type="button" class="btn btn-light" wire:click="clearBook">{{ __('Change') }}</button>
                @endunless
            </div>
        @else
            <input type="search" id="field-book-search" autocomplete="off" wire:model.live.debounce.300ms="bookSearch"
                   @class(['form-control', 'is-invalid' => $errors->has('book_id')]) placeholder="{{ __('Search by title, author or ISBN…') }}">
            @if (mb_strlen(trim($bookSearch)) >= 2)
                <div class="list-group position-absolute start-0 end-0 shadow-sm z-3 mt-1">
                    @forelse ($this->bookResults as $result)
                        <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2" wire:key="book-{{ $result->id }}"
                                wire:click="pickBook({{ $result->id }})" @disabled($result->available_quantity < 1)>
                            <span class="flex-grow-1 min-w-0 text-truncate">
                                <span class="fw-medium">{{ $result->title }}</span>
                                @if ($result->author)<small class="text-muted ms-1">{{ $result->author }}</small>@endif
                            </span>
                            <span @class(['badge flex-shrink-0', 'bg-success-subtle text-success' => $result->available_quantity > 0, 'bg-danger-subtle text-danger' => $result->available_quantity < 1])>
                                {{ $result->available_quantity > 0 ? __(':count on the shelf', ['count' => $result->available_quantity]) : __('All out') }}
                            </span>
                        </button>
                    @empty
                        <div class="list-group-item text-muted fs-13">{{ __('No book matches.') }}</div>
                    @endforelse
                </div>
            @endif
        @endif
        @error('book_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
    </div>

    {{-- Borrower --}}
    <div class="mb-4 position-relative">
        <label class="form-label" for="field-borrower-search">{{ __('Borrower') }}<span class="text-danger ms-1">*</span></label>
        @if ($borrower_id !== '' && $borrowerSearch === '')
            <div class="input-group">
                <span @class(['form-control bg-light text-truncate', 'is-invalid' => $errors->has('borrower_id')])>{{ $borrowerLabel }}</span>
                @unless ($fixedBorrower)
                    <button type="button" class="btn btn-light" wire:click="clearBorrower">{{ __('Change') }}</button>
                @endunless
            </div>
        @else
            <input type="search" id="field-borrower-search" autocomplete="off" wire:model.live.debounce.300ms="borrowerSearch"
                   @class(['form-control', 'is-invalid' => $errors->has('borrower_id')]) placeholder="{{ __('Search students and staff by name, admission no. or employee ID…') }}">
            @if (mb_strlen(trim($borrowerSearch)) >= 2)
                <div class="list-group position-absolute start-0 end-0 shadow-sm z-3 mt-1">
                    @forelse ($this->borrowerResults as $result)
                        <button type="button" class="list-group-item list-group-item-action" wire:click="pickBorrower({{ $result->id }})" wire:key="borrower-{{ $result->id }}">
                            <span class="fw-medium">{{ $result->name }}</span>
                            <small class="text-muted ms-1">
                                @if ($result->studentProfile)
                                    {{ collect([$result->studentProfile->admission_number, $result->studentProfile->placementLabel()])->filter()->implode(' · ') }}
                                @else
                                    {{ collect([__('Staff'), $result->staffProfile?->designation])->filter()->implode(' · ') }}
                                @endif
                            </small>
                        </button>
                    @empty
                        <div class="list-group-item text-muted fs-13">{{ __('No student or staff member matches.') }}</div>
                    @endforelse
                </div>
            @endif
        @endif
        @error('borrower_id') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror

        @if ($this->borrower)
            @php($limit = App\Services\Library\Library::limitFor($this->borrower))
            <div class="form-text">
                {{ __(':count of :limit books out', ['count' => $this->loans->count(), 'limit' => $limit]) }}
                @foreach ($this->loans as $loan)
                    <span class="d-block {{ $loan->isOverdue() ? 'text-danger' : '' }}">
                        <i class="ri-book-2-line me-1"></i>{{ $loan->book?->title }} · {{ $loan->isOverdue() ? __('overdue since :date', ['date' => $loan->due_on->format($format)]) : __('due :date', ['date' => $loan->due_on->format($format)]) }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    <div class="row">
        <div class="col-sm-6"><x-form.input name="issued_on" type="date" :label="__('Issued on')" required live :max="tenant()->today()->toDateString()" /></div>
        <div class="col-sm-6"><x-form.input name="due_on" type="date" :label="__('Due back')" required /></div>
    </div>
    <x-form.input name="remarks" :label="__('Remarks')" maxlength="255" wrapper="mb-0" :placeholder="__('e.g. Copy with a torn cover')" />
</x-modal.form>
