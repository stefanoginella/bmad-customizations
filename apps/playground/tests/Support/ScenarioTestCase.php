<?php
/**
 * The state every cron-driven scenario starts from and leaves behind.
 *
 * The AD-7 scenarios all read the same two places — the recorded outcome and
 * the cron queue — so they all need the same clean slate, and none of them may
 * leave a changed site key for the next one.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for the tests that drive WP-Cron.
 */
abstract class ScenarioTestCase extends TestCase
{
    /**
     * Every scenario starts from no recorded outcome and no queued retry.
     */
    protected function setUp(): void
    {
        parent::setUp();

        WpCli::run(['option', 'update', Playground::STATE_OPTION, '[]', '--format=json']);

        if ($this->eventsFor(Playground::RETRY_HOOK) !== []) {
            WpCli::run(['cron', 'event', 'delete', Playground::RETRY_HOOK]);
        }
    }

    /**
     * No scenario leaves a changed key behind.
     */
    protected function tearDown(): void
    {
        WpCli::run(['option', 'update', Playground::KEY_OPTION, Playground::siteKey()]);

        parent::tearDown();
    }

    /**
     * Every scheduled event for one hook.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function eventsFor(string $hook): array
    {
        return WpCli::cronEvents($hook);
    }
}
