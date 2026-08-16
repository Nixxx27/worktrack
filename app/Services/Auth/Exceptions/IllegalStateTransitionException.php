<?php

namespace App\Services\Auth\Exceptions;

use RuntimeException;

/**
 * A refused role/status change. Always carries a message safe to show an admin —
 * it explains what was refused and what to do instead.
 */
class IllegalStateTransitionException extends RuntimeException {}
