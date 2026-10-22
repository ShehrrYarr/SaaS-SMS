<?php

use App\Rules\SchoolSlug;
use Illuminate\Support\Facades\Validator;

function slugPasses(string $slug): bool
{
    return Validator::make(['slug' => $slug], ['slug' => [new SchoolSlug]])->passes();
}

it('accepts url-safe slugs', function (string $slug) {
    expect(slugPasses($slug))->toBeTrue();
})->with(['greenfield', 'oak-ridge-high', 'school42']);

it('rejects malformed slugs', function (string $slug) {
    expect(slugPasses($slug))->toBeFalse();
})->with(['Greenfield', 'oak_ridge', '-oak', 'oak--ridge', 'ab', 'has space', str_repeat('a', 61)]);

it('rejects reserved platform paths', function (string $slug) {
    expect(slugPasses($slug))->toBeFalse();
})->with(['super-admin', 'livewire', 'login', 'storage', 'api', 'teacher']);
