<?php
/**
 * The AD-7 no-op invariant: every failure branch of the phone-home.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

use Brain\Monkey\Functions;
use RuntimeException;
use WOptimize\Connector\Phone_Home;
use WOptimize\Connector\Site_Key;
use WP_Error;

/**
 * Covers Phone_Home.
 */
final class PhoneHomeTest extends TestCase {

	/**
	 * A well-formed key: 40 alphanumeric characters.
	 *
	 * @var string
	 */
	private const VALID_KEY = 'aB3dE5gH7jK9mN1pQ3sT5vW7yZ9bD1fH3jL5nP7r';

	/**
	 * What `record()` last wrote, captured from `update_option()`.
	 *
	 * @var array<string, mixed>
	 */
	private $recorded = array();

	/**
	 * Single events queued during the test, as hook => timestamp.
	 *
	 * @var array<string, int>
	 */
	private $single_events = array();

	/**
	 * Hooks cleared with `wp_unschedule_hook()` during the test.
	 *
	 * @var array<int, string>
	 */
	private $cleared_hooks = array();

	/**
	 * With no key stored, nothing leaves the site at all.
	 *
	 * @return void
	 */
	public function test_no_key_sends_nothing(): void {
		$this->stub_environment( '' );

		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();

		Phone_Home::run_scheduled();

		$this->assertSame( 'no_key', $this->recorded['last_result'] );
		$this->assertSame( 0, $this->recorded['last_http_status'] );
		$this->assertGreaterThan( 0, $this->recorded['last_attempt_at'] );
	}

	/**
	 * A 200 records success and queues no retry.
	 *
	 * @return void
	 */
	public function test_successful_report_records_ok_and_does_not_retry(): void {
		$this->stub_environment( self::VALID_KEY );
		$this->stub_portal_response( 200 );

		Functions\expect( 'wp_schedule_single_event' )->never();

		Phone_Home::run_scheduled();

		$this->assertSame( 'ok', $this->recorded['last_result'] );
		$this->assertSame( 200, $this->recorded['last_http_status'] );
	}

	/**
	 * The request carries the key, the user agent, and the report as JSON.
	 *
	 * @return void
	 */
	public function test_request_shape(): void {
		$this->stub_environment( self::VALID_KEY );

		$captured = array();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured = array(
						'url'  => $url,
						'args' => $args,
					);

					return array( 'response' => array( 'code' => 200 ) );
				}
			);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		Phone_Home::run_scheduled();

		$this->assertSame( Phone_Home::endpoint_url(), $captured['url'] );
		$this->assertStringEndsWith( '/api/connector/v1/phone-home', $captured['url'] );
		$this->assertSame( self::VALID_KEY, $captured['args']['headers'][ Site_Key::HEADER ] );
		$this->assertSame(
			'WOptimize-Connector/' . WOPTIMIZE_CONNECTOR_VERSION,
			$captured['args']['headers']['User-Agent']
		);
		$this->assertSame( 'application/json', $captured['args']['headers']['Content-Type'] );
		$this->assertSame( Phone_Home::TIMEOUT, $captured['args']['timeout'] );
		$this->assertArrayNotHasKey( 'sslverify', $captured['args'], 'sslverify stays at the WordPress default.' );

		$body = json_decode( $captured['args']['body'], true );

		$this->assertIsArray( $body );
		$this->assertSame( WOPTIMIZE_CONNECTOR_VERSION, $body['connector_version'] );
		$this->assertSame( 'https://client.example/wp-json/woptimize/v1', $body['rest_base'] );
	}

	/**
	 * Any 4xx is permanent-quiet: recorded, never retried.
	 *
	 * @dataProvider provide_client_error_statuses
	 *
	 * @param int $status The status the portal returned.
	 * @return void
	 */
	public function test_client_errors_are_permanent_quiet( int $status ): void {
		$this->stub_environment( self::VALID_KEY );
		$this->stub_portal_response( $status );

		Functions\expect( 'wp_schedule_single_event' )->never();

		Phone_Home::run_scheduled();

		$this->assertSame( 'client_error', $this->recorded['last_result'] );
		$this->assertSame( $status, $this->recorded['last_http_status'] );
	}

	/**
	 * Answers that must never lead to a retry: every 4xx, and anything else
	 * outside the 2xx and 5xx ranges.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function provide_client_error_statuses(): array {
		return array(
			'unknown or revoked key' => array( 401 ),
			'endpoint gone'          => array( 404 ),
			'body rejected'          => array( 422 ),
			'unexpected redirect'    => array( 302 ),
		);
	}

	/**
	 * A 5xx queues exactly one retry, fifteen minutes out.
	 *
	 * @return void
	 */
	public function test_server_error_queues_one_retry(): void {
		$this->stub_environment( self::VALID_KEY );
		$this->stub_portal_response( 503 );

		$before = time();

		Phone_Home::run_scheduled();

		$this->assertSame( 'server_error', $this->recorded['last_result'] );
		$this->assertSame( 503, $this->recorded['last_http_status'] );
		$this->assertArrayHasKey( Phone_Home::RETRY_HOOK, $this->single_events );
		$this->assertGreaterThanOrEqual(
			$before + Phone_Home::RETRY_DELAY,
			$this->single_events[ Phone_Home::RETRY_HOOK ]
		);
	}

	/**
	 * A transport error is treated like a 5xx: one retry, nothing raised.
	 *
	 * @return void
	 */
	public function test_transport_error_queues_one_retry(): void {
		$this->stub_environment( self::VALID_KEY );

		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http_request_failed', 'Could not resolve host.' ) );

		Phone_Home::run_scheduled();

		$this->assertSame( 'transport_error', $this->recorded['last_result'] );
		$this->assertSame( 0, $this->recorded['last_http_status'] );
		$this->assertArrayHasKey( Phone_Home::RETRY_HOOK, $this->single_events );
	}

	/**
	 * The retry records its own result and never queues another.
	 *
	 * @return void
	 */
	public function test_the_retry_never_reschedules(): void {
		$this->stub_environment( self::VALID_KEY );
		$this->stub_portal_response( 503 );

		Functions\expect( 'wp_schedule_single_event' )->never();

		Phone_Home::run_retry();

		$this->assertSame( 'server_error', $this->recorded['last_result'] );
		$this->assertSame( array(), $this->single_events );
	}

	/**
	 * A regular run that settles the matter drops a retry left over from an
	 * earlier 5xx.
	 *
	 * Without this, a retry queued at one slot could still fire after the next
	 * daily run answered `ok`, `4xx`, or "no key" — and a 4xx followed by a
	 * retry is exactly what permanent-quiet forbids (AD-7).
	 *
	 * @dataProvider provide_settled_outcomes
	 *
	 * @param string   $site_key The key in the option.
	 * @param int|null $status   The portal's status, or null for no request.
	 * @param string   $expected The result that must be recorded.
	 * @return void
	 */
	public function test_a_settled_run_clears_a_pending_retry( string $site_key, ?int $status, string $expected ): void {
		$this->stub_environment( $site_key );

		if ( null !== $status ) {
			$this->stub_portal_response( $status );
		}

		Phone_Home::run_scheduled();

		$this->assertSame( $expected, $this->recorded['last_result'] );
		$this->assertSame(
			array( Phone_Home::RETRY_HOOK ),
			$this->cleared_hooks,
			'A settled run must drop any retry left over from an earlier failure.'
		);
		$this->assertSame( array(), $this->single_events, 'and must queue nothing new.' );
	}

	/**
	 * Outcomes that settle the matter until the next daily slot.
	 *
	 * @return array<string, array{0: string, 1: int|null, 2: string}>
	 */
	public static function provide_settled_outcomes(): array {
		return array(
			'accepted'    => array( self::VALID_KEY, 200, 'ok' ),
			'revoked key' => array( self::VALID_KEY, 401, 'client_error' ),
			'no key'      => array( '', null, 'no_key' ),
		);
	}

	/**
	 * The retry itself clears nothing: a single event has already left the
	 * queue by the time it runs.
	 *
	 * @return void
	 */
	public function test_the_retry_clears_no_hooks(): void {
		$this->stub_environment( self::VALID_KEY );
		$this->stub_portal_response( 200 );

		Phone_Home::run_retry();

		$this->assertSame( 'ok', $this->recorded['last_result'] );
		$this->assertSame( array(), $this->cleared_hooks );
	}

	/**
	 * A failure while recording a failure is swallowed too.
	 *
	 * `record()` writes an option, and an option write can throw — a dead
	 * database, a filter on `pre_update_option` that raises. Nothing may escape
	 * the cron path (AD-7).
	 *
	 * @return void
	 */
	public function test_a_failure_while_recording_never_escapes(): void {
		$writes = 0;

		Functions\when( 'get_option' )->justReturn( self::VALID_KEY );
		Functions\when( 'update_option' )->alias(
			static function () use ( &$writes ) {
				++$writes;

				throw new RuntimeException( 'The options table is unavailable.' );
			}
		);

		$this->stub_scheduler();
		$this->stub_site_report();

		Functions\when( 'wp_remote_post' )->alias(
			static function () {
				throw new RuntimeException( 'Something in the HTTP stack exploded.' );
			}
		);

		Phone_Home::run_scheduled();

		$this->assertSame(
			1,
			$writes,
			'The exception was recorded once, and the failure to record it was swallowed.'
		);
	}

	/**
	 * A retry already in the queue is not duplicated.
	 *
	 * @return void
	 */
	public function test_a_queued_retry_is_not_duplicated(): void {
		$this->stub_environment( self::VALID_KEY );
		$this->stub_portal_response( 500 );

		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 300 );
		Functions\expect( 'wp_schedule_single_event' )->never();

		Phone_Home::run_scheduled();

		$this->assertSame( 'server_error', $this->recorded['last_result'] );
	}

	/**
	 * A Throwable in the cron path is caught, recorded, and never escapes.
	 *
	 * @return void
	 */
	public function test_a_throwable_is_caught_and_recorded(): void {
		$this->stub_environment( self::VALID_KEY );

		Functions\when( 'wp_remote_post' )->alias(
			static function () {
				throw new RuntimeException( 'Something in the HTTP stack exploded.' );
			}
		);

		Phone_Home::run_scheduled();

		$this->assertSame( 'exception', $this->recorded['last_result'] );
		$this->assertSame( 0, $this->recorded['last_http_status'] );
	}

	/**
	 * Activation schedules the daily event once.
	 *
	 * @return void
	 */
	public function test_schedule_creates_the_daily_event(): void {
		$scheduled = array();

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $timestamp, $recurrence, $hook ) use ( &$scheduled ) {
				$scheduled = array(
					'timestamp'  => $timestamp,
					'recurrence' => $recurrence,
					'hook'       => $hook,
				);

				return true;
			}
		);

		Phone_Home::schedule();

		$this->assertSame( 'daily', $scheduled['recurrence'] );
		$this->assertSame( Phone_Home::HOOK, $scheduled['hook'] );
		$this->assertGreaterThan( time(), $scheduled['timestamp'] );
	}

	/**
	 * A second activation does not stack a second daily event.
	 *
	 * @return void
	 */
	public function test_schedule_is_idempotent(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 100 );
		Functions\expect( 'wp_schedule_event' )->never();

		Phone_Home::schedule();
	}

	/**
	 * Deactivation clears both hooks.
	 *
	 * @return void
	 */
	public function test_unschedule_clears_both_hooks(): void {
		$cleared = array();

		Functions\when( 'wp_unschedule_hook' )->alias(
			static function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;

				return 1;
			}
		);

		Phone_Home::unschedule();

		$this->assertSame( array( Phone_Home::HOOK, Phone_Home::RETRY_HOOK ), $cleared );
	}

	/**
	 * A self-update queues one report so the portal learns the new version.
	 *
	 * @return void
	 */
	public function test_self_update_queues_one_report(): void {
		$this->stub_scheduler();

		Functions\when( 'plugin_basename' )->justReturn( 'woptimize-connector/woptimize-connector.php' );

		Phone_Home::on_upgrade(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'woptimize-connector/woptimize-connector.php' ),
			)
		);

		$this->assertArrayHasKey( Phone_Home::HOOK, $this->single_events );
	}

	/**
	 * Any other plugin's update is none of the connector's business.
	 *
	 * @dataProvider provide_unrelated_upgrades
	 *
	 * @param mixed $hook_extra What the upgrader reported.
	 * @return void
	 */
	public function test_unrelated_upgrades_queue_nothing( $hook_extra ): void {
		$this->stub_scheduler();

		Functions\when( 'plugin_basename' )->justReturn( 'woptimize-connector/woptimize-connector.php' );
		Functions\expect( 'wp_schedule_single_event' )->never();

		Phone_Home::on_upgrade( null, $hook_extra );

		$this->assertSame( array(), $this->single_events );
	}

	/**
	 * Upgrader payloads that must be ignored.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function provide_unrelated_upgrades(): array {
		return array(
			'another plugin'  => array(
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'akismet/akismet.php' ),
				),
			),
			'a theme'         => array(
				array(
					'action' => 'update',
					'type'   => 'theme',
					'themes' => array( 'twentytwentyfive' ),
				),
			),
			'a fresh install' => array(
				array(
					'action' => 'install',
					'type'   => 'plugin',
					'plugin' => 'woptimize-connector/woptimize-connector.php',
				),
			),
			'nothing usable'  => array( 'not an array' ),
		);
	}

	/**
	 * The endpoint is the portal constant plus the contract path.
	 *
	 * @return void
	 */
	public function test_endpoint_url(): void {
		$this->assertSame(
			rtrim( WOPTIMIZE_PORTAL_URL, '/' ) . Phone_Home::PATH,
			Phone_Home::endpoint_url()
		);
	}

	/**
	 * An unreadable state option degrades to zeroes.
	 *
	 * @return void
	 */
	public function test_state_defaults_when_nothing_was_recorded(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertSame(
			array(
				'last_attempt_at'  => 0,
				'last_result'      => '',
				'last_http_status' => 0,
			),
			Phone_Home::state()
		);
	}

	/**
	 * Stubs the option layer, the scheduler, and the site report.
	 *
	 * @param string $site_key The key in the option.
	 * @return void
	 */
	private function stub_environment( string $site_key ): void {
		$this->recorded = array();

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( $site_key ) {
				return Site_Key::OPTION === $name ? $site_key : $default_value;
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( Phone_Home::OPTION === $name ) {
					$this->recorded = $value;
				}

				return true;
			}
		);

		$this->stub_scheduler();
		$this->stub_site_report();
	}

	/**
	 * Captures single events instead of touching WP-Cron.
	 *
	 * @return void
	 */
	private function stub_scheduler(): void {
		$this->single_events = array();
		$this->cleared_hooks = array();

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->single_events[ $hook ] = $timestamp;

				return true;
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			function ( $hook ) {
				$this->cleared_hooks[] = $hook;

				return 1;
			}
		);
	}

	/**
	 * Makes `wp_remote_post()` answer with one status code.
	 *
	 * @param int $status The HTTP status to return.
	 * @return void
	 */
	private function stub_portal_response( int $status ): void {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => $status ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
	}
}
