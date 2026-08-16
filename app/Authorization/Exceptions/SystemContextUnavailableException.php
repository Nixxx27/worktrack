<?php

namespace App\Authorization\Exceptions;

use RuntimeException;

/**
 * Thrown when SystemContext::run() is attempted from a request that carries a
 * session — i.e. from a real user's HTTP request, where an all-trackers bypass
 * would be a direct NFR-S3 breach.
 */
class SystemContextUnavailableException extends RuntimeException {}
