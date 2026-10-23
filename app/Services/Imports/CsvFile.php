<?php

namespace App\Services\Imports;

use App\Tables\ActionFailed;
use Illuminate\Support\Str;
use SplFileObject;

/**
 * Reads an uploaded CSV (comma or semicolon separated, as Excel saves it)
 * into rows keyed by normalised header: "Guardian Phone" → guardian_phone.
 */
class CsvFile
{
    /**
     * @return array{headers: list<string>, rows: list<array{line: int, values: array<string, string>}>}
     *
     * @throws ActionFailed
     */
    public static function read(string $path, int $maxRows): array
    {
        $file = new SplFileObject($path, 'r');
        $first = (string) $file->fgets();
        $file->rewind();

        // Excel in many locales saves with semicolons.
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter, '"', '');

        $headers = null;
        $rows = [];
        $line = 0;

        foreach ($file as $record) {
            $line++;

            if (! is_array($record) || $record === [null]) {
                continue;
            }

            $record = array_map(fn ($value) => self::clean((string) $value), $record);

            if ($headers === null) {
                $headers = array_map(fn ($header) => self::key($header), $record);

                continue;
            }

            if (implode('', $record) === '') {
                continue;
            }

            if (count($rows) >= $maxRows) {
                throw new ActionFailed(__('The file has more than :max rows. Split it into smaller files.', ['max' => $maxRows]));
            }

            $values = [];

            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $values[$header] = $record[$index] ?? '';
                }
            }

            $rows[] = ['line' => $line, 'values' => $values];
        }

        if ($headers === null) {
            throw new ActionFailed(__('The file is empty.'));
        }

        return ['headers' => array_values(array_filter($headers)), 'rows' => $rows];
    }

    /**
     * A CSV document from a header row and data rows.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int|string, scalar|null>>  $rows
     */
    public static function write(array $headers, iterable $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\u{FEFF}"); // so Excel reads UTF-8 names correctly
        fputcsv($stream, $headers, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($value) => self::neutralise((string) $value), array_values($row)), ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public static function key(string $header): string
    {
        return Str::of($header)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private static function clean(string $value): string
    {
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return trim($value);
    }

    /**
     * Stop spreadsheet apps treating a cell as a formula.
     */
    private static function neutralise(string $value): string
    {
        if ($value === '' || ! in_array($value[0], ['=', '+', '-', '@'], true) || preg_match('/^[+-]?[\d\s().-]+$/', $value)) {
            return $value;
        }

        return "'".$value;
    }
}
