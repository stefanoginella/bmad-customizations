<?php
/**
 * The suite has to run at the client-site floor, or it proves nothing.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

/**
 * Covers the runtime the suite itself is executed on.
 */
final class FloorTest extends TestCase {

	/**
	 * The connector supports PHP 8.1 on client sites, and PHP 8.1 is where the
	 * suite must run.
	 *
	 * A green run on PHP 8.4 would happily accept 8.2+ syntax the floor cannot
	 * parse. PHPCompatibility catches most of it, but only the real 8.1 parser
	 * catches all of it — so the runtime is asserted, not assumed.
	 *
	 * @return void
	 */
	public function test_the_suite_runs_at_the_client_floor(): void {
		$this->assertTrue(
			PHP_VERSION_ID >= 80100 && PHP_VERSION_ID < 80200,
			sprintf(
				'This suite must run on PHP 8.1 — the client-site floor — but is running on %s. '
					. 'Run it inside the connector\'s own DDEV project: `cd packages/connector && ddev composer test`. '
					. 'Do not run it from the host or from another app\'s container, which are on PHP 8.4.',
				PHP_VERSION
			)
		);
	}
}
