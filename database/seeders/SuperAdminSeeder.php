<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the platform super admin from SUPER_ADMIN_* in .env. If the account
 * already exists it is left alone, so re-seeding never resets a changed password.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        ['name' => $name, 'email' => $email, 'password' => $password] = config('tenancy.super_admin');

        if (blank($email) || blank($password)) {
            throw new RuntimeException('Set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in .env before seeding.');
        }

        tenancy()->runCentral(function () use ($name, $email, $password): void {
            $exists = User::query()->whereNull('school_id')->where('email', $email)->exists();

            if ($exists) {
                $this->command?->info("Super admin {$email} already exists.");

                return;
            }

            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'user_type' => UserType::SuperAdmin,
                'status' => UserStatus::Active,
            ]);

            $this->command?->info("Super admin {$email} created (password from .env).");
        });
    }
}
