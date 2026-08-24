<?php
/**
 * What `Plugin::boot()` wires up — and what it must not do.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use WOptimize\Connector\Phone_Home;
use WOptimize\Connector\Plugin;
use WOptimize\Connector\Rest_Controller;
use WOptimize\Connector\Settings;
use WOptimize\Connector\Site_Key;

/**
 * Covers Plugin::boot() and the hook surface of the plugin.
 */
final class BootTest extends TestCase {

	/**
	 * Booting registers every hook the connector owns, with the right callback.
	 *
	 * The callback is asserted, not merely the hook name: a hook wired to the
	 * wrong handler is worse than a missing one.
	 *
	 * @dataProvider provide_expected_actions
	 *
	 * @param string $hook     The action name.
	 * @param array  $callback The callable that must be attached.
	 * @param string $why      What the hook is for.
	 * @return void
	 */
	public function test_boot_registers_action( string $hook, array $callback, string $why ): void {
		Plugin::boot();

		$this->assertTrue( Actions\has( $hook, $callback, 10 ), $why );
	}

	/**
	 * Every action the connector owns, with its handler.
	 *
	 * @return array<string, array{0: string, 1: array, 2: string}>
	 */
	public static function provide_expected_actions(): array {
		return array(
			'REST routes'      => array(
				'rest_api_init',
				array( new Rest_Controller(), 'register_routes' ),
				'The REST routes must be registered.',
			),
			'daily slot'       => array(
				Phone_Home::HOOK,
				array( Phone_Home::class, 'run_scheduled' ),
				'The daily slot needs a handler.',
			),
			'retry slot'       => array(
				Phone_Home::RETRY_HOOK,
				array( Phone_Home::class, 'run_retry' ),
				'The single retry needs its own handler, so it never reschedules.',
			),
			'key changed'      => array(
				'update_option_' . Site_Key::OPTION,
				array( Phone_Home::class, 'run_scheduled' ),
				'Saving a changed key must report to the portal at once.',
			),
			'first key'        => array(
				'add_option_' . Site_Key::OPTION,
				array( Phone_Home::class, 'run_scheduled' ),
				'A first-ever key must report to the portal at once.',
			),
			'self-update'      => array(
				'upgrader_process_complete',
				array( Phone_Home::class, 'on_upgrade' ),
				'A self-update must tell the portal about the new version.',
			),
			'settings page'    => array(
				'admin_menu',
				array( Settings::class, 'add_page' ),
				'The settings page must exist.',
			),
			'setting register' => array(
				'admin_init',
				array( Settings::class, 'register' ),
				'The setting must be registered.',
			),
		);
	}

	/**
	 * The version header filter runs at the documented priority.
	 *
	 * @return void
	 */
	public function test_boot_registers_the_version_header_filter(): void {
		Plugin::boot();

		$this->assertTrue(
			Filters\has( 'rest_post_dispatch', array( Rest_Controller::class, 'add_version_header' ), 10 ),
			'Every response in the namespace needs the version header.'
		);
	}

	/**
	 * Booting touches no network, no option, and no schedule.
	 *
	 * Every WordPress function the connector could call is unstubbed here, so
	 * any attempt to reach out at load time would raise an error and fail this
	 * test. There is nothing to assert beyond "it did not blow up".
	 *
	 * @return void
	 */
	public function test_boot_does_no_work(): void {
		$this->expectNotToPerformAssertions();

		Plugin::boot();
	}

	/**
	 * The connector never touches the WordPress update machinery. Update
	 * delivery is story 9, through the `Update URI:` header (AD-17).
	 *
	 * @return void
	 */
	public function test_boot_does_not_filter_plugin_updates(): void {
		Plugin::boot();

		$this->assertFalse( Filters\has( 'update_plugins_portal.woptimize.io' ) );
		$this->assertFalse( Filters\has( 'site_transient_update_plugins' ) );
		$this->assertFalse( Filters\has( 'pre_set_site_transient_update_plugins' ) );
	}
}
