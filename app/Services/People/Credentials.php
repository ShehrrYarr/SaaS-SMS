<?php

namespace App\Services\People;

use SensitiveParameter;

/**
 * Sign-in details for a new account, shown to the administrator once.
 */
final readonly class Credentials
{
    public function __construct(
        public string $name,
        public string $username,
        #[SensitiveParameter] public string $password,
        public string $role,
    ) {}

    /**
     * @return array{name: string, username: string, password: string, role: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'username' => $this->username,
            'password' => $this->password,
            'role' => $this->role,
        ];
    }
}
