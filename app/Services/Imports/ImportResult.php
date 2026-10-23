<?php

namespace App\Services\Imports;

use App\Services\People\Credentials;

final readonly class ImportResult
{
    /**
     * @param  list<Credentials>  $credentials  for every account the import created
     */
    public function __construct(
        public int $imported,
        public array $credentials,
    ) {}
}
