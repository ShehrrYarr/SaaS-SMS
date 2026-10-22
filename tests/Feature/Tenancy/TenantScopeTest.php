<?php

use App\Enums\UserType;
use App\Models\Campus;
use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Tenancy\Exceptions\TenantContextException;
use Illuminate\Database\Eloquent\MassAssignmentException;

beforeEach(function () {
    $this->schoolA = makeSchool(['slug' => 'alpha']);
    $this->schoolB = makeSchool(['slug' => 'bravo']);

    tenancy()->run($this->schoolA, fn () => Campus::factory()->count(2)->create());
    tenancy()->run($this->schoolB, fn () => Campus::factory()->create(['name' => 'Bravo Main']));
});

it('only returns rows belonging to the active school', function () {
    tenancy()->set($this->schoolA);
    expect(Campus::count())->toBe(2)
        ->and(Campus::pluck('school_id')->unique()->all())->toBe([$this->schoolA->id]);

    tenancy()->set($this->schoolB);
    expect(Campus::count())->toBe(1)
        ->and(Campus::first()->name)->toBe('Bravo Main');
});

it('fails closed when no tenant context is set', function () {
    tenancy()->forget();

    expect(Campus::count())->toBe(0);
});

it('sees every school in the central context', function () {
    tenancy()->enterCentral();

    expect(Campus::count())->toBe(3);
});

it('cannot find another school\'s row by id', function () {
    $bravoCampus = tenancy()->run($this->schoolB, fn () => Campus::first());

    tenancy()->set($this->schoolA);

    expect(Campus::find($bravoCampus->id))->toBeNull();
});

it('stamps new rows with the active school', function () {
    $campus = tenancy()->run($this->schoolA, fn () => Campus::create(['name' => 'North Campus']));

    expect($campus->school_id)->toBe($this->schoolA->id);
});

it('refuses to create a tenant row without a school', function () {
    tenancy()->forget();

    Campus::create(['name' => 'Orphan Campus']);
})->throws(TenantContextException::class);

it('refuses to write a row into another school', function () {
    tenancy()->set($this->schoolA);

    $campus = new Campus(['name' => 'Smuggled']);
    $campus->forceFill(['school_id' => $this->schoolB->id])->save();
})->throws(TenantContextException::class);

it('refuses to move a row to another school', function () {
    tenancy()->set($this->schoolA);

    Campus::first()->forceFill(['school_id' => $this->schoolB->id])->save();
})->throws(TenantContextException::class);

it('never mass-assigns school_id', function () {
    tenancy()->set($this->schoolA);

    Campus::create(['name' => 'Sneaky', 'school_id' => $this->schoolB->id]);
})->throws(MassAssignmentException::class);

it('restores the previous context after run()', function () {
    tenancy()->set($this->schoolA);

    tenancy()->run($this->schoolB, fn () => expect(tenant()->id)->toBe($this->schoolB->id));

    expect(tenant()->id)->toBe($this->schoolA->id);
});

it('stamps tenant pivots on attach', function () {
    tenancy()->set($this->schoolA);

    $parent = ParentProfile::create(['user_id' => makeSchoolUser($this->schoolA, UserType::Parent)->id]);
    $student = StudentProfile::create([
        'user_id' => makeSchoolUser($this->schoolA, UserType::Student)->id,
        'admission_number' => 'ADM-0001',
    ]);

    $parent->children()->attach($student, ['relation' => 'mother', 'is_primary' => true]);

    $this->assertDatabaseHas('parent_student', [
        'school_id' => $this->schoolA->id,
        'parent_profile_id' => $parent->id,
        'student_profile_id' => $student->id,
    ]);
});
