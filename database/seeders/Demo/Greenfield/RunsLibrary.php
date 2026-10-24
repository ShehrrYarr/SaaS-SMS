<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\FineStatus;
use App\Models\BookIssue;
use App\Models\LibraryBook;
use App\Models\User;
use App\Services\Library\Library;
use App\Tables\ActionFailed;
use Carbon\CarbonImmutable;

/**
 * The library: a catalogue, and loans through the term. Most come back on
 * time; some late with a fine (paid at the desk, waived, added to fees or
 * still owed); one book is lost; a few are out now, some overdue.
 */
trait RunsLibrary
{
    /**
     * title => [author, publisher, category, rack, copies, price, readers (junior | senior | staff)]
     */
    private const BOOKS = [
        'The Very Hungry Caterpillar' => ['Eric Carle', 'Puffin', 'Picture Books', 'A-1', 4, 950, 'junior'],
        'The Gruffalo' => ['Julia Donaldson', 'Macmillan', 'Picture Books', 'A-1', 3, 1100, 'junior'],
        'Where the Wild Things Are' => ['Maurice Sendak', 'HarperCollins', 'Picture Books', 'A-1', 2, 1200, 'junior'],
        'Brown Bear, Brown Bear, What Do You See?' => ['Bill Martin Jr.', 'Puffin', 'Picture Books', 'A-2', 3, 850, 'junior'],
        'Aesop\'s Fables' => ['Aesop', 'Usborne', 'Fiction', 'A-2', 4, 1300, 'junior'],
        'The Magic Faraway Tree' => ['Enid Blyton', 'Hodder', 'Fiction', 'B-1', 3, 1250, 'senior'],
        'Five on a Treasure Island' => ['Enid Blyton', 'Hodder', 'Fiction', 'B-1', 3, 1150, 'senior'],
        'Charlotte\'s Web' => ['E. B. White', 'HarperCollins', 'Fiction', 'B-2', 3, 1200, 'senior'],
        'Matilda' => ['Roald Dahl', 'Puffin', 'Fiction', 'B-2', 2, 1100, 'senior'],
        'The BFG' => ['Roald Dahl', 'Puffin', 'Fiction', 'B-2', 2, 1100, 'senior'],
        'Charlie and the Chocolate Factory' => ['Roald Dahl', 'Puffin', 'Fiction', 'B-2', 3, 1100, 'senior'],
        'Wonder' => ['R. J. Palacio', 'Corgi', 'Fiction', 'B-3', 2, 1600, 'senior'],
        'Holes' => ['Louis Sachar', 'Bloomsbury', 'Fiction', 'B-3', 2, 1450, 'senior'],
        'The Lion, the Witch and the Wardrobe' => ['C. S. Lewis', 'HarperCollins', 'Fiction', 'B-3', 2, 1350, 'senior'],
        'The Secret Garden' => ['Frances Hodgson Burnett', 'Puffin Classics', 'Classics', 'C-1', 2, 900, 'senior'],
        'The Jungle Book' => ['Rudyard Kipling', 'Puffin Classics', 'Classics', 'C-1', 2, 900, 'senior'],
        'Treasure Island' => ['Robert Louis Stevenson', 'Puffin Classics', 'Classics', 'C-1', 2, 900, 'senior'],
        'Black Beauty' => ['Anna Sewell', 'Puffin Classics', 'Classics', 'C-2', 2, 850, 'senior'],
        'Heidi' => ['Johanna Spyri', 'Puffin Classics', 'Classics', 'C-2', 1, 850, 'senior'],
        'Alice\'s Adventures in Wonderland' => ['Lewis Carroll', 'Puffin Classics', 'Classics', 'C-2', 2, 800, 'senior'],
        'The Little Prince' => ['Antoine de Saint-Exupéry', 'Egmont', 'Classics', 'C-2', 2, 1000, 'senior'],
        'Chacha Chhakkan' => ['Imtiaz Ali Taj', 'Sang-e-Meel', 'Urdu', 'D-1', 3, 600, 'senior'],
        'Bang-e-Dra' => ['Allama Muhammad Iqbal', 'Iqbal Academy', 'Urdu', 'D-1', 2, 750, 'staff'],
        'Urdu Qaida' => ['Feroz Sons', 'Feroz Sons', 'Urdu', 'D-1', 6, 300, 'junior'],
        'Stories of the Prophets' => ['Ibn Kathir', 'Darussalam', 'Islamiyat', 'D-4', 3, 1800, 'senior'],
        'My First Science Book' => ['DK', 'DK', 'Science', 'E-1', 4, 1500, 'junior'],
        'The Usborne Science Encyclopedia' => ['Usborne', 'Usborne', 'Science', 'E-1', 1, 4500, 'senior'],
        'How Things Work' => ['DK', 'DK', 'Science', 'E-1', 2, 3200, 'senior'],
        'Oxford Children\'s Dictionary' => ['Oxford', 'Oxford University Press', 'Reference', 'R-1', 3, 2500, 'senior'],
        'Oxford School Atlas for Pakistan' => ['Oxford', 'Oxford University Press', 'Reference', 'R-1', 4, 1900, 'senior'],
        'Maths Made Easy: Times Tables' => ['DK', 'DK', 'Mathematics', 'E-2', 3, 700, 'junior'],
        'The Elements of Style' => ['William Strunk Jr. and E. B. White', 'Pearson', 'Teaching', 'T-1', 1, 2200, 'staff'],
        'Teach Like a Champion' => ['Doug Lemov', 'Jossey-Bass', 'Teaching', 'T-1', 1, 4200, 'staff'],
    ];

    private function setUpLibrary(): void
    {
        $librarian = $this->people['laila'];

        $this->as($librarian, function () {
            $library = app(Library::class);

            foreach (self::BOOKS as $title => [$author, $publisher, $category, $rack, $copies, $price]) {
                $library->saveBook([
                    'title' => $title,
                    'author' => $author,
                    'publisher' => $publisher,
                    'category' => $category,
                    'rack_number' => $rack,
                    'quantity' => $copies,
                    'price' => $price,
                ]);
            }
        });
    }

    private function planLibrary(): void
    {
        $librarian = $this->people['laila'];

        // The demo sign-ins' loans.
        $stories = [
            ['ayesha.khan', 'Matilda', -27, 'late', 6, FineStatus::Paid],
            ['ayesha.khan', 'Charlotte\'s Web', -5, 'out'],
            ['zain.khan', 'The Gruffalo', -17, 'out'],
            ['zain.khan', 'The Very Hungry Caterpillar', -40, 'on time'],
            ['sana.malik', 'The Elements of Style', -3, 'out'],
        ];

        // Everyone else: about two loans a school day.
        $readers = array_values(array_filter($this->pupils, fn (array $pupil) => ! str_starts_with($pupil['section'], 'PREP') && $pupil['family'] > 1));
        $teachers = array_values(array_diff(array_keys(self::STAFF), ['sana']));
        $titles = ['junior' => [], 'senior' => [], 'staff' => []];

        foreach (self::BOOKS as $title => $book) {
            $titles[$book[6]][] = $title;
        }

        for ($i = 0, $loans = $this->scaled(45, 8); $i < $loans; $i++) {
            $from = -$this->dice->int(2, (int) min(60, (int) $this->yearStart->diffInDays($this->today) - 4));

            if ($this->dice->chance(15)) {
                $borrower = $this->people[$this->dice->pick($teachers)]->username;
                $title = $this->dice->pick([...$titles['staff'], ...$titles['senior']]);
            } else {
                $pupil = $this->dice->pick($readers);
                $borrower = $pupil['student']->user?->username;
                $title = $this->dice->pick(self::CLASSES[strstr($pupil['section'], '-', true)][2] <= 2 ? $titles['junior'] : $titles['senior']);
            }

            $dueIn = $from + 14;
            $outcome = match (true) {
                $dueIn >= 0 => $this->dice->chance(75) ? 'out' : 'early',
                default => (string) $this->dice->weighted(['on time' => 64, 'late' => 24, 'out' => 8, 'lost' => 2, 'renewed' => 6]),
            };

            $stories[] = [$borrower, $title, $from, $outcome, $this->dice->int(1, 9), $this->dice->weighted([FineStatus::Paid->value => 55, FineStatus::Waived->value => 15, FineStatus::Unpaid->value => 22, FineStatus::Invoiced->value => 8])];
        }

        foreach ($stories as $story) {
            [$username, $title, $from, $outcome] = $story;
            $issued = $this->schoolDay($this->today->addDays($from), back: true);

            if ($issued->lte($this->yearStart) || $username === null) {
                continue;
            }

            $this->at($this->on($issued, sprintf('%02d:%02d', $this->dice->int(9, 13), $this->dice->int(0, 59))), fn () => $this->lend(
                $username, $title, $issued, $outcome, $story[4] ?? 0, $story[5] ?? FineStatus::Paid, $librarian,
            ));
        }
    }

    private function lend(string $username, string $title, CarbonImmutable $issued, string $outcome, int $daysLate, FineStatus|string $settle, User $librarian): void
    {
        $library = app(Library::class);
        $borrower = User::query()->where('username', $username)->first();
        $book = LibraryBook::query()->where('title', $title)->first();

        if (! $borrower || ! $book) {
            return;
        }

        $due = $issued->addDays(14);

        try {
            $issue = $this->as($librarian, fn () => $library->issue($book, $borrower, $this->date($issued), $this->date($due), null, $librarian));
        } catch (ActionFailed) {
            return;
        }

        $settle = $settle instanceof FineStatus ? $settle : FineStatus::from($settle);
        $desk = fn (CarbonImmutable $day) => $this->on($this->schoolDay($day), sprintf('%02d:%02d', $this->dice->int(9, 14), $this->dice->int(0, 59)));

        match ($outcome) {
            'early' => $this->at($desk($issued->addDays($this->dice->int(3, 9))), fn () => $this->bringBack($issue, 0, $settle)),
            'on time' => $this->at($desk($due->subDays($this->dice->int(0, 5))), fn () => $this->bringBack($issue, 0, $settle)),
            'late' => $this->at($desk($due->addDays($daysLate)), fn () => $this->bringBack($issue, $daysLate, $settle)),
            'renewed' => $this->at($desk($due->subDay()), function () use ($issue, $library, $librarian, $desk) {
                $renewed = $this->as($librarian, fn () => $library->renew($issue->refresh(), $librarian));

                $this->at($desk(CarbonImmutable::parse($renewed->due_on->toDateString(), self::TIMEZONE)->subDays(2)), fn () => $this->bringBack($renewed, 0, FineStatus::Paid));
            }),
            'lost' => $this->at($desk($due->addDays(3)), function () use ($issue, $library, $librarian, $book) {
                $this->as($librarian, fn () => $library->markLost($issue->refresh(), tenant()->today(), (float) $book->price, 'Lost on the way home; the family will pay for a new copy.', $librarian));
                $this->at($this->on($this->addSchoolDays(tenant()->today(), 4), '10:15'), fn () => $this->as($librarian, fn () => $library->settleFine($issue->refresh(), FineStatus::Paid, $librarian)));
            }),
            default => null,
        };
    }

    /**
     * A book comes back; any fine is paid, waived, put on the fee invoice
     * (students only) or left owing.
     */
    private function bringBack(BookIssue $issue, int $daysLate, FineStatus $settle): void
    {
        $library = app(Library::class);
        $librarian = $this->people['laila'];
        $issue = $issue->refresh();

        if (! $issue->isOut()) {
            return;
        }

        $today = tenant()->today();

        try {
            $this->as($librarian, fn () => $library->receive($issue, $today, $daysLate > 0 ? Library::fineFor($issue, $today) : 0, null, $librarian));
        } catch (ActionFailed) {
            return;
        }

        if (! $issue->refresh()->hasFineOwed()) {
            return;
        }

        $student = $issue->borrower?->user_type?->value === 'student';

        match ($settle) {
            FineStatus::Paid => $this->as($librarian, fn () => $library->settleFine($issue, FineStatus::Paid, $librarian)),
            FineStatus::Waived => $this->at($this->on($this->addSchoolDays($today, 1), '12:00'), fn () => $this->as($librarian, fn () => $library->settleFine($issue->refresh(), FineStatus::Waived, $librarian))),
            FineStatus::Invoiced => $student
                ? $this->at($this->on($this->addSchoolDays($today, 2), '11:30'), fn () => $this->as($this->people['omar'], fn () => $library->billFine($issue->refresh(), $this->people['omar'])))
                : null,
            default => null,
        };
    }
}
