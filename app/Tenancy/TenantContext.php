<?php

namespace App\Tenancy;

use App\Models\School;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/**
 * The school (tenant) the current request, job or command is acting for.
 *
 * There are three states:
 *  - tenant:  a school is set, so tenant-scoped models are filtered to it.
 *  - central: the Super Admin console, so tenant scoping is lifted.
 *  - none:    nothing resolved, so tenant-scoped queries return no rows.
 *
 * Bound as a scoped singleton, so it resets between requests and queued jobs.
 */
class TenantContext
{
    private ?School $school = null;

    private bool $central = false;

    public function set(School $school): void
    {
        $this->school = $school;
        $this->central = false;

        $this->sync();
    }

    public function enterCentral(): void
    {
        $this->school = null;
        $this->central = true;

        $this->sync();
    }

    public function forget(): void
    {
        $this->school = null;
        $this->central = false;

        $this->sync();
    }

    public function school(): ?School
    {
        return $this->school;
    }

    public function id(): ?int
    {
        return $this->school?->getKey();
    }

    public function hasSchool(): bool
    {
        return $this->school !== null;
    }

    public function isCentral(): bool
    {
        return $this->central;
    }

    /**
     * Run a callback as the given school, then restore the previous context.
     *
     * @template T
     *
     * @param  Closure(School): T  $callback
     * @return T
     */
    public function run(School $school, Closure $callback): mixed
    {
        return $this->within(fn () => $this->set($school), $callback);
    }

    /**
     * Run a callback in the central (Super Admin) context, then restore.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runCentral(Closure $callback): mixed
    {
        return $this->within(fn () => $this->enterCentral(), $callback);
    }

    private function within(Closure $enter, Closure $callback): mixed
    {
        [$previousSchool, $previousCentral] = [$this->school, $this->central];

        $enter();

        try {
            return $callback($this->school);
        } finally {
            $this->school = $previousSchool;
            $this->central = $previousCentral;

            $this->sync();
        }
    }

    /**
     * Push the active tenant into everything else that needs to know about it.
     */
    private function sync(): void
    {
        // Spatie teams: roles and permissions resolve against this school.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->id());

        // route('admin.dashboard') fills in {school} automatically.
        URL::defaults([config('tenancy.route_parameter') => $this->school?->slug]);

        // Carried into queued jobs and restored on the worker (see AppServiceProvider).
        Context::addHidden('tenancy', [
            'school_id' => $this->id(),
            'central' => $this->central,
        ]);
    }
}
