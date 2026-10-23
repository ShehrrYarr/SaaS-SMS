<?php

namespace App\Tables;

use RuntimeException;

/**
 * Thrown by a table action to refuse the operation with a message shown to
 * the user as an error toast (e.g. "The current academic year can't be deleted").
 */
class ActionFailed extends RuntimeException {}
