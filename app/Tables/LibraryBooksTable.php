<?php

namespace App\Tables;

use App\Models\LibraryBook;
use App\Services\Library\Library;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * The library catalogue with copies on the shelf and out on loan.
 */
class LibraryBooksTable extends Table
{
    public function permission(): ?string
    {
        return 'library.view';
    }

    public function query(): Builder
    {
        return LibraryBook::query();
    }

    public function defaultSort(): array
    {
        return ['title', 'asc'];
    }

    public function columns(): array
    {
        return [
            Column::make('title', __('Book'))->sortable()->searchable()
                ->format(fn (string $title, LibraryBook $book) => new HtmlString(
                    '<span class="fw-medium text-body d-block">'.e($title).($book->edition ? ' <small class="text-muted">('.e($book->edition).')</small>' : '').'</span>'
                    .'<small class="text-muted">'.e(collect([$book->author, $book->isbn ? 'ISBN '.$book->isbn : null])->filter()->implode(' · ')).'</small>',
                )),
            Column::make('category', __('Category'))->sortable()->hiddenOnMobile(),
            Column::make('rack_number', __('Rack'))->sortable()->hiddenOnMobile(),
            Column::make('available_quantity', __('On the shelf'))->sortable()
                ->format(fn (int $available, LibraryBook $book) => new HtmlString(
                    '<span class="badge bg-'.($available > 0 ? 'success' : 'danger').'-subtle text-'.($available > 0 ? 'success' : 'danger').'">'
                    .e(__(':available of :total', ['available' => $available, 'total' => $book->quantity])).'</span>'
                    .($book->copiesOut() > 0 ? '<small class="d-block text-muted">'.e(trans_choice(':count out on loan|:count out on loan', $book->copiesOut(), ['count' => $book->copiesOut()])).'</small>' : ''),
                )),
            Column::make('price', __('Price'))->sortable()->money()->hiddenOnMobile(),
        ];
    }

    public function filters(): array
    {
        $categories = LibraryBook::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');

        return array_values(array_filter([
            $categories->isEmpty() ? null : Filter::make('category', __('Category'))
                ->options($categories->mapWithKeys(fn (string $category) => [$category => $category])->all()),
            Filter::make('availability', __('Availability'))
                ->options(['available' => __('On the shelf'), 'out' => __('All copies out'), 'loaned' => __('Some copies out')])
                ->query(fn (Builder $query, string $value) => match ($value) {
                    'available' => $query->where('available_quantity', '>', 0),
                    'out' => $query->where('available_quantity', '<=', 0),
                    default => $query->whereColumn('available_quantity', '<', 'quantity'),
                }),
        ]));
    }

    public function actions(): array
    {
        return [
            Action::make('issue', __('Issue'))
                ->icon('ri-arrow-right-up-line')
                ->color('success')
                ->modal('forms.book-issue', fn (LibraryBook $book) => ['book' => $book->id])
                ->can('library.issue')
                ->visible(fn (LibraryBook $book) => $book->available_quantity > 0),

            Action::edit('forms.library-book', 'book', 'library.manage'),

            Action::delete('library.manage', __('Its loan history is kept.'))
                ->handle(fn (LibraryBook $book) => app(Library::class)->deleteBook($book, auth()->user()))
                ->success(fn (LibraryBook $book) => __(':title removed from the catalogue.', ['title' => $book->title])),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where('title', 'like', $term)
            ->orWhere('author', 'like', $term)
            ->orWhere('isbn', 'like', $term)
            ->orWhere('publisher', 'like', $term));
    }

    public function searchPlaceholder(): string
    {
        return __('Search by title, author, ISBN or publisher…');
    }

    public function emptyTitle(): string
    {
        return __('No books in the catalogue');
    }

    public function emptyMessage(): ?string
    {
        return __('Add the books your library holds and how many copies of each.');
    }
}
