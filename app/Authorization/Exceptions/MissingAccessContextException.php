<?php

namespace App\Authorization\Exceptions;

use RuntimeException;

/**
 * Thrown when a tracker-scoped query runs with no AccessContext bound.
 *
 * This is a bug signal, never a normal condition. It must NEVER be caught and
 * converted into an empty result — that would turn a loud failure into a silent
 * one, which is the whole thing the fail-closed design exists to prevent.
 */
class MissingAccessContextException extends RuntimeException {}
