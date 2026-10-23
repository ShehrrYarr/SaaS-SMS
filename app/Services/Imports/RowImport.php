<?php

namespace App\Services\Imports;

use App\Enums\Gender;
use App\Models\User;
use App\Services\People\Accounts;
use App\Tables\ActionFailed;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A bulk import from CSV, in two steps:
 *
 *  1. check(): read the file and validate every row against the school's
 *     data, without saving anything. Rows come back with their errors so the
 *     user can fix the file.
 *  2. import(): save the rows (all or nothing), creating accounts, and
 *     return the sign-in details of every new account.
 */
abstract class RowImport
{
    public const MAX_ROWS = 1000;

    /** Values seen earlier in the file, per column, to catch duplicates. */
    protected array $seen = [];

    /** File line of the row being prepared. */
    protected int $line = 0;

    public function __construct(protected readonly Accounts $accounts) {}

    /**
     * Column key => [label, required, example, hint].
     *
     * @return array<string, array{0: string, 1: bool, 2: string, 3?: string}>
     */
    abstract public function columns(): array;

    /**
     * Validate and normalise one row.
     *
     * @param  array<string, string>  $values
     * @return array{data: array<string, mixed>, errors: list<string>, notes?: list<string>}
     */
    abstract protected function prepare(array $values): array;

    /**
     * @param  list<array<string, mixed>>  $rows  the "data" of valid rows from check()
     *
     * @throws ActionFailed
     */
    abstract public function import(array $rows, User $by): ImportResult;

    /**
     * @return array{rows: list<array{line: int, data: array<string, mixed>, errors: list<string>, notes: list<string>, values: array<string, string>}>, valid: int, invalid: int}
     *
     * @throws ActionFailed
     */
    public function check(string $path): array
    {
        $csv = CsvFile::read($path, self::MAX_ROWS);

        $missing = collect($this->columns())
            ->filter(fn (array $column) => $column[1])
            ->keys()
            ->diff($csv['headers']);

        if ($missing->isNotEmpty()) {
            throw new ActionFailed(__('The file is missing these columns: :columns. Start from the template.', ['columns' => $missing->implode(', ')]));
        }

        if ($csv['rows'] === []) {
            throw new ActionFailed(__('The file has a header row but no data.'));
        }

        $this->seen = [];
        $rows = [];

        foreach ($csv['rows'] as $row) {
            $values = array_intersect_key($row['values'], $this->columns());
            $this->line = $row['line'];
            $result = $this->prepare($values);

            $rows[] = [
                'line' => $row['line'],
                'values' => $values,
                'data' => $result['data'],
                'errors' => $result['errors'],
                'notes' => $result['notes'] ?? [],
            ];
        }

        $invalid = collect($rows)->filter(fn (array $row) => $row['errors'] !== [])->count();

        return ['rows' => $rows, 'valid' => count($rows) - $invalid, 'invalid' => $invalid];
    }

    /**
     * The CSV template: the header row and one example row.
     */
    public function template(): string
    {
        $columns = $this->columns();

        return CsvFile::write(array_keys($columns), [array_map(fn (array $column) => $column[2], $columns)]);
    }

    /**
     * A sign-in identifier that must be free in the school and unique in
     * the file.
     *
     * @param  list<string>  $errors
     */
    protected function checkIdentifier(string $column, string $label, string $value, array &$errors): void
    {
        if ($value === '') {
            return;
        }

        $key = mb_strtolower($value);

        if (isset($this->seen['identity'][$key])) {
            $errors[] = __(':label :value appears more than once in the file.', ['label' => $label, 'value' => $value]);
        } elseif (! $this->accounts->isAvailable($column, $value)) {
            $errors[] = __(':label :value is already used by another account.', ['label' => $label, 'value' => $value]);
        }

        $this->seen['identity'][$key] = $this->line;
    }

    /**
     * "2014-03-25", or a date in the school's date format, or 25/03/2014.
     */
    protected function date(string $value): Carbon|false|null
    {
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', tenant()->setting('date_format'), 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);

                if ($date && $date->format($format) === $value) {
                    return $date;
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        return false;
    }

    protected function gender(string $value): Gender|false|null
    {
        return match (mb_strtolower($value)) {
            '' => null,
            'm', 'male', 'boy' => Gender::Male,
            'f', 'female', 'girl' => Gender::Female,
            'o', 'other' => Gender::Other,
            default => false,
        };
    }
}
