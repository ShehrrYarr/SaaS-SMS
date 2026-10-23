<?php

use App\Models\Campus;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/login', fn () => 'login')->name('login');

    Route::middleware(['web', 'tenant'])->group(function () {
        Route::get('/{school}/_probe', fn () => tenant()->slug);
        Route::get('/{school}/_params', fn () => response()->json(request()->route()->parameters()));
        Route::get('/{school}/_campus/{campus}', fn (Campus $campus) => $campus->name);
        Route::get('/{school}/_secure', fn () => (string) auth()->id())->middleware('auth');
    });

    Route::middleware(['web', 'central', 'auth', 'super-admin'])
        ->get('/super-admin/_probe', fn () => (string) Campus::count());

    Route::getRoutes()->refreshNameLookups();

    $this->schoolA = makeSchool(['slug' => 'alpha']);
    $this->schoolB = makeSchool(['slug' => 'bravo']);
});

it('resolves the school from the path prefix', function () {
    $this->get('/alpha/_probe')->assertOk()->assertSee('alpha');
});

it('returns 404 for an unknown school', function () {
    $this->get('/nowhere/_probe')->assertNotFound();
});

it('blocks a suspended school', function () {
    $this->schoolB->update(['status' => 'suspended']);

    $this->get('/bravo/_probe')->assertForbidden();
});

it('does not pass {school} on to the route action', function () {
    $this->get('/alpha/_params')->assertOk()->assertExactJson([]);
});

it('scopes route-model binding to the school in the URL', function () {
    $bravoCampus = tenancy()->run($this->schoolB, fn () => Campus::factory()->create(['name' => 'Bravo HQ']));

    $this->get("/bravo/_campus/{$bravoCampus->id}")->assertOk()->assertSee('Bravo HQ');
    $this->get("/alpha/_campus/{$bravoCampus->id}")->assertNotFound();
});

it('lets a user into their own school', function () {
    $user = makeSchoolUser($this->schoolA);

    $this->actingAs($user)->get('/alpha/_secure')->assertOk()->assertSee((string) $user->id);
});

it('signs out a user who opens another school', function () {
    $user = makeSchoolUser($this->schoolA);

    $this->actingAs($user)->get('/bravo/_secure')->assertRedirect('/bravo/login');

    $this->assertGuest();
});

it('opens the central console to super admins only', function () {
    tenancy()->run($this->schoolA, fn () => Campus::factory()->count(2)->create());
    tenancy()->run($this->schoolB, fn () => Campus::factory()->create());

    $this->actingAs(makeSchoolUser($this->schoolA))->get('/super-admin/_probe')->assertForbidden();

    $this->actingAs(makeSuperAdmin())->get('/super-admin/_probe')->assertOk()->assertSee('3');
});
