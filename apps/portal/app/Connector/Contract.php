<?php

namespace App\Connector;

use LogicException;

/**
 * The contract facts, in one place.
 *
 * `packages/connector/openapi.yaml` is the source of truth (AD-4).
 * `tests/Feature/ContractTest.php` ties the header names, the path prefix, the
 * `SiteReport` required lists, and the documented response statuses to that
 * file. The key length, the key pattern, and the timeout are portal facts the
 * contract carries only as prose — no test can tie those two together, so keep
 * them in step by hand.
 */
final class Contract
{
    /** The header that carries the portal-issued site key, both directions. */
    public const SITE_KEY_HEADER = 'X-Woptimize-Site-Key';

    /** The response header the connector reports its version in. */
    public const VERSION_HEADER = 'X-Woptimize-Connector-Version';

    /** Number of characters in a site key. */
    public const KEY_LENGTH = 40;

    /**
     * The pattern every site key matches.
     *
     * The `D` modifier matters: without it `$` also matches before a trailing
     * newline, so "<40 chars>\n" would pass as a key.
     */
    public const KEY_PATTERN = '/^[A-Za-z0-9]{'.self::KEY_LENGTH.'}$/D';

    /** Timeout, in seconds, of an outbound call to a client site. */
    public const TIMEOUT_SECONDS = 10;

    /** The User-Agent the portal calls a client site with. */
    public const USER_AGENT = 'WOptimize-Portal';

    /**
     * The path prefix the portal serves the connector endpoints under.
     *
     * It spells out the whole wire path, Laravel's `api` route-group prefix
     * included.
     */
    public const PATH_PREFIX = '/api/connector/v1';

    /** Laravel's own prefix for everything in `routes/api.php`. */
    private const API_GROUP_PREFIX = '/api/';

    /**
     * The group prefix `routes/api.php` adds on top of Laravel's own.
     *
     * @throws LogicException When `PATH_PREFIX` no longer sits under `/api/`.
     */
    public static function routePrefix(): string
    {
        if (! str_starts_with(self::PATH_PREFIX, self::API_GROUP_PREFIX)) {
            throw new LogicException(
                'Contract::PATH_PREFIX must start with "'.self::API_GROUP_PREFIX.'": '.
                'routes/api.php is already grouped under Laravel\'s api prefix.'
            );
        }

        return substr(self::PATH_PREFIX, strlen(self::API_GROUP_PREFIX));
    }

    /**
     * The `Request::is()` pattern matching every connector endpoint.
     */
    public static function requestPattern(): string
    {
        return ltrim(self::PATH_PREFIX, '/').'/*';
    }
}
