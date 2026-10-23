<?php

namespace App\Services\Schools;

use App\Models\School;
use App\Models\User;
use SensitiveParameter;

/**
 * Result of provisioning: the new school, its first administrator and the
 * administrator's temporary password (shown once, never stored in clear).
 */
final class ProvisionedSchool
{
    public function __construct(
        public readonly School $school,
        public readonly User $admin,
        #[SensitiveParameter] public readonly string $password,
    ) {}
}
