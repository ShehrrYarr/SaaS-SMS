<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Campus extends TenantModel
{
    use AuditsChanges, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'status' => RecordStatus::class,
        ];
    }

    /**
     * @return HasMany<SchoolClass, $this>
     */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * @return HasMany<Classroom, $this>
     */
    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    /**
     * @param  Builder<Campus>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_main')->orderBy('name');
    }

    /**
     * The school's main campus (the default for new classes and rooms).
     */
    public static function main(): ?self
    {
        return static::query()->ordered()->first();
    }

    /**
     * Make this the school's only main campus.
     */
    public function makeMain(): void
    {
        DB::transaction(function () {
            static::query()->whereKeyNot($this->getKey())->where('is_main', true)->get()->each->update(['is_main' => false]);
            $this->update(['is_main' => true]);
        });
    }
}
