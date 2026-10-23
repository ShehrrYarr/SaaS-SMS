<?php

namespace App\Services\Students;

use App\Models\StudentProfile;
use App\Services\People\Credentials;

/**
 * A student just admitted, with the sign-in details of every account the
 * admission created (the student's, and any new parents').
 */
final readonly class Admission
{
    /**
     * @param  list<Credentials>  $credentials
     */
    public function __construct(
        public StudentProfile $student,
        public array $credentials,
    ) {}
}
