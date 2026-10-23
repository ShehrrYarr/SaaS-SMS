<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Administrator-driven password resets (there is no email, so nobody resets
 * their own forgotten password). The new password is temporary: the user
 * must replace it at next sign-in, and every existing session of theirs is
 * ended.
 */
class PasswordResetter
{
    public function reset(User $user, #[SensitiveParameter] string $password, ?User $by = null): void
    {
        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'remember_token' => null,
        ])->save();

        $this->endSessions($user);

        activity('accounts')->performedOn($user)->causedBy($by)->log('Password reset');
    }

    private function endSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }
}
