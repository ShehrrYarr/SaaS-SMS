<?php

use Illuminate\Support\Facades\Context;

it('carries the tenant into queued work and restores it on the worker', function () {
    $school = makeSchool();

    tenancy()->set($school);
    $payload = Context::dehydrate(); // what Laravel serialises into a job payload

    tenancy()->forget();
    Context::flush();
    expect(tenant())->toBeNull();

    Context::hydrate($payload); // what the worker does before handling the job

    expect(tenant()?->id)->toBe($school->id);
});

it('carries the central context into queued work', function () {
    tenancy()->enterCentral();
    $payload = Context::dehydrate();

    tenancy()->forget();
    Context::flush();

    Context::hydrate($payload);

    expect(tenancy()->isCentral())->toBeTrue();
});
