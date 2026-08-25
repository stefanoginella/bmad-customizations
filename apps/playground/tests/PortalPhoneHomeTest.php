<?php
/**
 * The portal-hosted half of the contract, driven with the body the live
 * connector actually produces.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests;

use League\OpenAPIValidation\PSR7\OperationAddress;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WOptimize\Playground\Tests\Support\Contract;
use WOptimize\Playground\Tests\Support\Playground;

/**
 * Covers the matrix rows "Phone-home OK", "Phone-home refused", and
 * "Phone-home invalid".
 */
final class PortalPhoneHomeTest extends TestCase
{
    /**
     * Matrix row "Phone-home OK".
     */
    public function test_the_portal_accepts_the_live_site_report(): void
    {
        $response = $this->postReport(Playground::siteKey(), $this->liveReport());

        self::assertSame(200, $response->getStatusCode());

        Contract::assertResponseMatches(new OperationAddress('/phone-home', 'post'), $response);

        self::assertSame(['ok' => true], json_decode((string) $response->getBody(), true));
    }

    /**
     * Matrix row "Phone-home refused".
     */
    public function test_the_portal_refuses_an_unknown_key_with_401(): void
    {
        $response = $this->postReport(Playground::UNKNOWN_KEY, $this->liveReport());

        self::assertSame(401, $response->getStatusCode());

        Contract::assertResponseMatches(new OperationAddress('/phone-home', 'post'), $response);
    }

    /**
     * Matrix row "Phone-home invalid".
     *
     * `multisite` is a JSON boolean in the contract, so the string `"yes"` is
     * not the same fact and the portal must refuse the whole report.
     */
    public function test_the_portal_refuses_a_report_that_fails_validation_with_422(): void
    {
        $response = $this->postReport(
            Playground::siteKey(),
            array_merge($this->liveReport(), ['multisite' => 'yes'])
        );

        self::assertSame(422, $response->getStatusCode());

        Contract::assertResponseMatches(new OperationAddress('/phone-home', 'post'), $response);
    }

    /**
     * The body every phone-home in this file sends: the connector's own report.
     *
     * @return array<string, mixed>
     */
    private function liveReport(): array
    {
        $response = Playground::get('/status', Playground::siteKey());

        self::assertSame(
            200,
            $response->getStatusCode(),
            'The phone-home body is the live /status report, so /status must answer first.'
        );

        // The body about to be posted is a contract artefact in its own right:
        // a `/status` that drifted would otherwise fail here as a portal `422`.
        Contract::assertResponseMatches(new OperationAddress('/status', 'get'), $response);

        $report = json_decode((string) $response->getBody(), true);

        self::assertIsArray($report);

        return $report;
    }

    /**
     * Posts a report to the portal with the given key.
     *
     * @param  array<string, mixed>  $report
     */
    private function postReport(string $key, array $report): ResponseInterface
    {
        return Playground::client()->post(Playground::phoneHomeUrl(), [
            'headers' => [
                Playground::KEY_HEADER => $key,
                'Accept' => 'application/json',
            ],
            'json' => $report,
        ]);
    }
}
