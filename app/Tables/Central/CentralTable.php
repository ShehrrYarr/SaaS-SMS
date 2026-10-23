<?php

namespace App\Tables\Central;

use App\Tables\Table;

/**
 * A table of the Super Admin console. Its queries span every school, so it
 * refuses to render anywhere but the central context.
 */
abstract class CentralTable extends Table
{
    public function authorize(): void
    {
        abort_unless(tenancy()->isCentral(), 404);

        parent::authorize();
    }
}
