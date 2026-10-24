<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;

/**
 * A title in the library catalogue and how many copies the school holds.
 * available_quantity is the copies on the shelf; the rest are out on loan.
 * Both are kept by App\Services\Library\Library.
 */
class LibraryBook extends TenantModel
{
    use AuditsChanges {
        getActivitylogOptions as defaultActivitylogOptions;
    }
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        // Copies going out and coming back are logged as loans, not as book edits.
        return $this->defaultActivitylogOptions()->logExcept(['created_at', 'updated_at', 'available_quantity']);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'available_quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<BookIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(BookIssue::class);
    }

    public function copiesOut(): int
    {
        return max(0, $this->quantity - $this->available_quantity);
    }

    /**
     * "Title — Author".
     */
    public function label(): string
    {
        return $this->title.($this->author ? ' — '.$this->author : '');
    }
}
