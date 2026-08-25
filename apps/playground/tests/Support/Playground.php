<?php
/**
 * The playground's own facts: where it lives, which key it carries, and how a
 * test talks to it.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests\Support;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Addresses, the fixture key, and the HTTP client the suite calls with.
 *
 * The names below are contract and plugin facts, so they are constants. The
 * addresses and the key come from the container environment, so they are
 * methods.
 */
final class Playground
{
    /** The header that carries the portal-issued site key, both directions. */
    public const KEY_HEADER = 'X-Woptimize-Site-Key';

    /** The response header the connector reports its version in. */
    public const VERSION_HEADER = 'X-Woptimize-Connector-Version';

    /** The option the connector caches the site key in. */
    public const KEY_OPTION = 'woptimize_connector_site_key';

    /** The option the connector records the last phone-home outcome in. */
    public const STATE_OPTION = 'woptimize_connector_phone_home';

    /** The recurring daily phone-home hook. */
    public const PHONE_HOME_HOOK = 'woptimize_connector_phone_home';

    /** The single-event hook of the one allowed retry (AD-7). */
    public const RETRY_HOOK = 'woptimize_connector_phone_home_retry';

    /** The portal-hosted phone-home path, appended to the portal URL. */
    public const PHONE_HOME_PATH = '/api/connector/v1/phone-home';

    /** The connector's REST namespace under the site's `wp-json` root. */
    public const REST_NAMESPACE = '/wp-json/woptimize/v1';

    /** The environment variable holding the fixture site key. */
    public const KEY_ENV = 'WOPTIMIZE_TEST_SITE_KEY';

    /**
     * A well-formed key that belongs to no site.
     *
     * Every refusal scenario presents this one, so "the key is fine, the site
     * is not" is the only thing under test — never the format check.
     */
    public const UNKNOWN_KEY = 'UNKNOWNPLAYGROUNDKEY00000000000000000000';

    /** The environment variable holding the portal the connector reports to. */
    public const PORTAL_ENV = 'WOPTIMIZE_PORTAL_URL';

    /**
     * The playground site's own URL, without a trailing slash.
     */
    public static function url(): string
    {
        return rtrim(self::env('DDEV_PRIMARY_URL'), '/');
    }

    /**
     * The connector's REST base on the playground, without a trailing slash.
     */
    public static function restBase(): string
    {
        return self::url().self::REST_NAMESPACE;
    }

    /**
     * The portal the playground reports to, without a trailing slash.
     */
    public static function portalUrl(): string
    {
        return rtrim(self::env(self::PORTAL_ENV), '/');
    }

    /**
     * The absolute URL of the portal-hosted phone-home endpoint.
     */
    public static function phoneHomeUrl(): string
    {
        return self::portalUrl().self::PHONE_HOME_PATH;
    }

    /**
     * The fixture site key, read from the container environment.
     */
    public static function siteKey(): string
    {
        return self::env(self::KEY_ENV);
    }

    /**
     * GETs one connector-hosted path, presenting a site key when given one.
     *
     * @param  string  $path  The path under the REST base, e.g. `/ping`.
     * @param  string|null  $key  The key to present; no header at all when null.
     */
    public static function get(string $path, ?string $key): ResponseInterface
    {
        $headers = $key === null ? [] : [self::KEY_HEADER => $key];

        return self::client()->get(self::restBase().$path, ['headers' => $headers]);
    }

    /**
     * A Guzzle client that hands back 4xx and 5xx responses instead of throwing.
     *
     * A refusal is an outcome the suite asserts on, and the contract validator
     * needs the response object to do it.
     */
    public static function client(): Client
    {
        return new Client([
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /**
     * One required container environment variable, exactly as it is set.
     *
     * Every address and the fixture key come from `.ddev/config.yaml` or from
     * DDEV itself. A missing one means the suite is not running where it thinks
     * it is, so it says so instead of quietly testing the wrong site. The value
     * is never reshaped here — a key is 40 characters, trailing slash or not.
     *
     * @throws RuntimeException When the variable is unset or empty.
     */
    private static function env(string $name): string
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(
                "{$name} is not set in the container environment. Run the suite "
                .'inside the playground container: '
                .'`ddev exec vendor/bin/phpunit --exclude-group offboarded`.'
            );
        }

        return $value;
    }
}
