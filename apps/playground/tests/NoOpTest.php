<?php
/**
 * AD-7 on real cron: every remote failure is a silent no-op, and the only
 * trace is the recorded outcome.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests;

use WOptimize\Playground\Tests\Support\Playground;
use WOptimize\Playground\Tests\Support\ScenarioTestCase;
use WOptimize\Playground\Tests\Support\WpCli;

/**
 * Covers the matrix rows "Cron happy", "Bad key", and "Unreachable".
 *
 * The methods run in declaration order, which is what lets the row after the
 * bad-key scenario observe that scenario's `tearDown()`.
 */
final class NoOpTest extends ScenarioTestCase
{
    /** A host that resolves nowhere, so the request never leaves as a request. */
    private const UNREACHABLE_PORTAL = 'https://unreachable.invalid';

    /**
     * Matrix row "Cron happy".
     */
    public function test_a_scheduled_run_records_ok_and_queues_no_retry(): void
    {
        WpCli::run(['cron', 'event', 'run', Playground::PHONE_HOME_HOOK]);

        $state = WpCli::optionJson(Playground::STATE_OPTION);

        self::assertSame('ok', $state['last_result'] ?? null);
        self::assertSame(200, (int) ($state['last_http_status'] ?? 0));
        self::assertSame([], $this->eventsFor(Playground::RETRY_HOOK));
        self::assertNotSame(
            [],
            $this->eventsFor(Playground::PHONE_HOME_HOOK),
            'The daily event stays scheduled after a run.'
        );
    }

    /**
     * Matrix row "Bad key".
     *
     * Saving the option fires a phone-home synchronously inside this CLI call.
     */
    public function test_a_bad_key_records_a_client_error_and_never_tightens_the_schedule(): void
    {
        WpCli::run(['option', 'update', Playground::KEY_OPTION, Playground::UNKNOWN_KEY]);

        $state = WpCli::optionJson(Playground::STATE_OPTION);

        self::assertSame('client_error', $state['last_result'] ?? null);
        self::assertSame(401, (int) ($state['last_http_status'] ?? 0));
        self::assertSame(
            [],
            $this->eventsFor(Playground::RETRY_HOOK),
            'A 4xx is permanent-quiet: nothing is scheduled (AD-7).'
        );
        self::assertNotSame([], $this->eventsFor(Playground::PHONE_HOME_HOOK));
    }

    /**
     * Matrix row "Bad key", error handling — the fixture key is restored.
     *
     * Declared right after the bad-key scenario so it reads the state that
     * scenario's `tearDown()` left behind.
     */
    public function test_the_bad_key_scenario_leaves_the_fixture_key_behind(): void
    {
        self::assertSame(
            Playground::siteKey(),
            trim(WpCli::run(['option', 'get', Playground::KEY_OPTION]))
        );
    }

    /**
     * Matrix row "Unreachable".
     */
    public function test_an_unreachable_portal_records_a_transport_error_and_queues_one_retry(): void
    {
        WpCli::run(
            ['cron', 'event', 'run', Playground::PHONE_HOME_HOOK],
            [Playground::PORTAL_ENV => self::UNREACHABLE_PORTAL]
        );

        $state = WpCli::optionJson(Playground::STATE_OPTION);

        self::assertSame('transport_error', $state['last_result'] ?? null);
        self::assertSame(0, (int) ($state['last_http_status'] ?? -1));
        self::assertCount(
            1,
            $this->eventsFor(Playground::RETRY_HOOK),
            'Exactly one retry, as a single event (AD-7).'
        );
    }

    /**
     * Matrix row "Unreachable", error handling — the retry never queues another.
     */
    public function test_the_single_retry_records_its_own_result_and_queues_no_other(): void
    {
        $env = [Playground::PORTAL_ENV => self::UNREACHABLE_PORTAL];

        WpCli::run(['cron', 'event', 'run', Playground::PHONE_HOME_HOOK], $env);

        self::assertCount(1, $this->eventsFor(Playground::RETRY_HOOK));

        WpCli::run(['cron', 'event', 'run', Playground::RETRY_HOOK], $env);

        $state = WpCli::optionJson(Playground::STATE_OPTION);

        self::assertSame('transport_error', $state['last_result'] ?? null);
        self::assertSame(0, (int) ($state['last_http_status'] ?? -1));
        self::assertSame(
            [],
            $this->eventsFor(Playground::RETRY_HOOK),
            'The retry records its own result and never reschedules itself.'
        );
    }

}
