<?php

namespace App\Enums;

/**
 * Mutually exclusive per account (AUTHZ-15), enforced by a CHECK constraint:
 * a Google account may never carry a password, and a local account must have one.
 */
enum AuthProvider: string
{
    case Google = 'google';
    case Local = 'local';   // break-glass only — see FR-1.8
}
