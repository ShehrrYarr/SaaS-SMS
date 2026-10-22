<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for every school-owned model.
 *
 * Attributes are mass assignable except `id`, and BelongsToSchool always
 * guards `school_id`. Livewire forms only ever pass validated fields.
 */
abstract class TenantModel extends Model
{
    use BelongsToSchool, HasFactory;

    protected $guarded = ['id'];
}
