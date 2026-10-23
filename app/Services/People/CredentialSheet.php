<?php

namespace App\Services\People;

use App\Services\Imports\CsvFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sign-in details of accounts created in bulk, kept encrypted for a short
 * time so the administrator can download them as a CSV sheet. Only the
 * person who ran the import can download it, and only in this school.
 */
class CredentialSheet
{
    public const MINUTES = 30;

    /**
     * @param  list<Credentials>  $credentials
     * @return string token for download()
     */
    public function store(array $credentials): string
    {
        $token = Str::random(40);

        Cache::put($this->key($token), Crypt::encryptString(json_encode(
            array_map(fn (Credentials $item) => $item->toArray(), $credentials),
        )), now()->addMinutes(self::MINUTES));

        return $token;
    }

    public function has(?string $token): bool
    {
        return $token !== null && Cache::has($this->key($token));
    }

    public function download(string $token, string $filename): ?StreamedResponse
    {
        $payload = Cache::get($this->key($token));

        if (! is_string($payload)) {
            return null;
        }

        $rows = json_decode(Crypt::decryptString($payload), true) ?: [];
        $url = tenant()->loginUrl();

        $csv = CsvFile::write(
            [__('Name'), __('Account'), __('Username'), __('Temporary password'), __('Sign-in address')],
            array_map(fn (array $row) => [$row['name'], $row['role'], $row['username'], $row['password'], $url], $rows),
        );

        return response()->streamDownload(fn () => print ($csv), $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'credential-sheet:'.tenant()->getKey().':'.auth()->id().':'.$token;
    }
}
