<?php

namespace App\Tenancy\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class TenantContextException extends RuntimeException
{
    public static function missing(Model $model): self
    {
        return new self(sprintf(
            'Cannot create [%s] without a school. Run it inside tenancy()->run($school, ...).',
            $model::class,
        ));
    }

    public static function mismatch(Model $model, int $currentSchoolId): self
    {
        return new self(sprintf(
            'Cannot create [%s] for school #%s while acting as school #%d.',
            $model::class,
            $model->getAttribute('school_id'),
            $currentSchoolId,
        ));
    }

    public static function reassignment(Model $model): self
    {
        return new self(sprintf(
            'Cannot move [%s #%s] to another school.',
            $model::class,
            $model->getKey(),
        ));
    }
}
