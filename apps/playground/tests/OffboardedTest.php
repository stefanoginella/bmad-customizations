<?php
/**
 * The one scenario the orchestrator has to stage from the host: the portal
 * deletes the row between two phone-homes.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests;

use WOptimize\Playground\Tests\Support\Playground;
use WOptimize\Playground\Tests\Support\ScenarioTestCase;
use WOptimize\Playground\Tests\Support\WpCli;

/**
 * Covers the matrix row "Offboarded".
 *
 * Excluded from the main run and run on its own, after `site:offboard`.
 *
 * @group offboarded
 */
final class OffboardedTest extends ScenarioTestCase
{
    /**
     * Matrix row "Offboarded".
     *
     * The key is still the one the portal issued; the row behind it is gone.
     * That is a 4xx, so it is permanent-quiet — the site goes silent until the
     * next daily slot instead of hammering a portal that will keep saying no.
     */
    public function test_a_phone_home_after_offboarding_records_a_client_error_401(): void
    {
        WpCli::run(['cron', 'event', 'run', Playground::PHONE_HOME_HOOK]);

        $state = WpCli::optionJson(Playground::STATE_OPTION);

        self::assertSame('client_error', $state['last_result'] ?? null);
        self::assertSame(401, (int) ($state['last_http_status'] ?? 0));

        self::assertSame([], $this->eventsFor(Playground::RETRY_HOOK));
    }
}
