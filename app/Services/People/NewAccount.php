<?php

namespace App\Services\People;

use App\Models\User;

/**
 * An account just created, with the sign-in details to hand over.
 */
final readonly class NewAccount
{
    public function __construct(
        public User $user,
        public Credentials $credentials,
    ) {}
}
