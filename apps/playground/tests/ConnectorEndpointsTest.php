<?php
/**
 * The connector-hosted half of the contract, served by the real plugin on a
 * real WordPress 6.7 at PHP 8.1.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests;

use League\OpenAPIValidation\PSR7\OperationAddress;
use PHPUnit\Framework\TestCase;
use WOptimize\Playground\Tests\Support\Contract;
use WOptimize\Playground\Tests\Support\Playground;

/**
 * Covers the matrix rows "Ping" and "Status".
 */
final class ConnectorEndpointsTest extends TestCase
{
    /**
     * Matrix row "Ping" — expected behaviour.
     */
    public function test_ping_answers_200_and_carries_the_version_header(): void
    {
        $response = Playground::get('/ping', Playground::siteKey());

        self::assertSame(200, $response->getStatusCode());

        Contract::assertResponseMatches(new OperationAddress('/ping', 'get'), $response);

        self::assertNotSame(
            '',
            $response->getHeaderLine(Playground::VERSION_HEADER),
            'The connector reports its version in the header, never in the ping body (AD-5).'
        );
    }

    /**
     * Matrix row "Ping" — error handling: no key, or a key that matches nothing.
     */
    public function test_ping_refuses_a_missing_or_unknown_key_with_401(): void
    {
        $address = new OperationAddress('/ping', 'get');

        $presented = [
            'no key at all' => null,
            'a well-formed key that matches nothing' => Playground::UNKNOWN_KEY,
        ];

        foreach ($presented as $case => $key) {
            $response = Playground::get('/ping', $key);

            self::assertSame(401, $response->getStatusCode(), $case);

            Contract::assertResponseMatches($address, $response);
        }
    }

    /**
     * Matrix row "Status" — expected behaviour.
     */
    public function test_status_answers_the_live_site_report(): void
    {
        $response = Playground::get('/status', Playground::siteKey());

        self::assertSame(200, $response->getStatusCode());

        Contract::assertResponseMatches(new OperationAddress('/status', 'get'), $response);

        $report = json_decode((string) $response->getBody(), true);

        self::assertIsArray($report);

        // The playground plays the worst client the floor allows.
        self::assertStringStartsWith('8.1.', (string) ($report['php_version'] ?? ''));

        // Pretty permalinks, or this would be `?rest_route=…` and the portal
        // would refuse it with a 422.
        self::assertSame(
            Playground::url().Playground::REST_NAMESPACE,
            $report['rest_base'] ?? null
        );

        self::assertSame(
            $response->getHeaderLine(Playground::VERSION_HEADER),
            $report['connector_version'] ?? null
        );
    }

    /**
     * Matrix row "Status" — error handling: a key that matches nothing.
     */
    public function test_status_refuses_an_unknown_key_with_401(): void
    {
        $response = Playground::get('/status', Playground::UNKNOWN_KEY);

        self::assertSame(401, $response->getStatusCode());

        Contract::assertResponseMatches(new OperationAddress('/status', 'get'), $response);
    }
}
