<?php

namespace App\Services\Auth;

/**
 * Canonical form used by the signup blocklist (AUTH-D21).
 *
 * Lowercase for every domain. Dot-stripping and +tag-stripping ONLY for Gmail,
 * because Gmail treats those as equivalent to the base address and other providers
 * do not — stripping dots universally would collide genuinely distinct addresses.
 *
 * Without this, a rejected applicant re-applies as first.last+2@gmail.com and
 * refills the approval queue indefinitely.
 */
class EmailCanonicalizer
{
    private const GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function canonicalize(string $email): string
    {
        $email = strtolower(trim($email));

        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (in_array($domain, self::GMAIL_DOMAINS, true)) {
            $local = explode('+', $local, 2)[0];
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';   // fold the alias domain
        }

        return $local.'@'.$domain;
    }
}
