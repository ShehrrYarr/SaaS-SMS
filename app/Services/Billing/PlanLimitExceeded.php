<?php

namespace App\Services\Billing;

use App\Tables\ActionFailed;

/**
 * Adding records would take a school past its plan's limit. Extends
 * ActionFailed so data-table actions show it as an error toast.
 */
class PlanLimitExceeded extends ActionFailed {}
