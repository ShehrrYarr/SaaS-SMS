<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LibraryBook extends TenantModel
{
    use SoftDeletes;

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
}
