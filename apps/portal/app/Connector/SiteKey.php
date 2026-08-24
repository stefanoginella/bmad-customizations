<?php

namespace App\Connector;

use Illuminate\Support\Str;

/**
 * Site key generation, hashing, and format checking (AD-5, AD-16).
 */
final class SiteKey
{
    /**
     * Generates a fresh site key.
     */
    public static function generate(): string
    {
        return Str::random(Contract::KEY_LENGTH);
    }

    /**
     * Hashes a site key into the value stored in `sites.site_key_hash`.
     *
     * The `encrypted` cast puts a fresh IV in every ciphertext, so the key
     * itself is not indexable; this hash is the lookup column.
     */
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Tells whether a candidate string could be a site key at all.
     */
    public static function isValidFormat(?string $key): bool
    {
        return $key !== null && preg_match(Contract::KEY_PATTERN, $key) === 1;
    }
}
