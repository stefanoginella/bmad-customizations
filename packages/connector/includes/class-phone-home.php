<?php
/**
 * The outbound half: the daily report the connector pushes to the portal.
 *
 * @package woptimize-connector
 */

namespace WOptimize\Connector;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the site report to the portal, and never lets that break the site.
 *
 * The whole class is the AD-7 no-op invariant in code:
 *
 * - no key       -> no request at all;
 * - any 4xx      -> permanent-quiet, silent until the next daily slot;
 * - 5xx or a transport error -> exactly one retry, 15 minutes later, and the
 *   retry never schedules another;
 * - a `Throwable` anywhere in the cron path is caught and recorded.
 *
 * The only visible trace of a failure is the last-result line on the settings
 * page. No admin notice, no `wp_die()`, no fatal.
 */
final class Phone_Home {

	/**
	 * The recurring daily cron hook.
	 *
	 * @var string
	 */
	const HOOK = 'woptimize_connector_phone_home';

	/**
	 * The single-event hook used for the one allowed retry.
	 *
	 * @var string
	 */
	const RETRY_HOOK = 'woptimize_connector_phone_home_retry';

	/**
	 * The option holding the last attempt's outcome.
	 *
	 * @var string
	 */
	const OPTION = 'woptimize_connector_phone_home';

	/**
	 * The portal-hosted path, appended to `WOPTIMIZE_PORTAL_URL`.
	 *
	 * @var string
	 */
	const PATH = '/api/connector/v1/phone-home';

	/**
	 * Request timeout, in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 10;

	/**
	 * How long after a 5xx or transport error the single retry fires, in seconds.
	 *
	 * @var int
	 */
	const RETRY_DELAY = 900;

	/**
	 * Registers the cron handlers and the two extra triggers.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'run_retry' ) );

		// A freshly pasted (or cleared) key is worth reporting at once.
		add_action( 'add_option_' . Site_Key::OPTION, array( __CLASS__, 'run_scheduled' ) );
		add_action( 'update_option_' . Site_Key::OPTION, array( __CLASS__, 'run_scheduled' ) );

		// So the portal learns the new version right after a self-update.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 10, 2 );
	}

	/**
	 * Schedules the daily event. Runs on activation.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Clears both events. Runs on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_unschedule_hook( self::HOOK );
		wp_unschedule_hook( self::RETRY_HOOK );
	}

	/**
	 * Cron handler for the daily slot, the key-save triggers, and self-update.
	 *
	 * @return void
	 */
	public static function run_scheduled() {
		self::run( false );
	}

	/**
	 * Cron handler for the single retry event.
	 *
	 * @return void
	 */
	public static function run_retry() {
		self::run( true );
	}

	/**
	 * Reports to the portal and records what happened.
	 *
	 * @param bool $is_retry True when this call is the one allowed retry, which
	 *                       may never schedule another.
	 * @return void
	 */
	public static function run( bool $is_retry = false ) {
		try {
			$key = Site_Key::get();

			if ( '' === $key ) {
				self::record( 'no_key', 0 );
				self::clear_retry( $is_retry );

				return;
			}

			$response = self::send( $key );

			if ( is_wp_error( $response ) ) {
				self::record( 'transport_error', 0 );
				self::maybe_retry( $is_retry );

				return;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( $status >= 200 && $status < 300 ) {
				self::record( 'ok', $status );
				self::clear_retry( $is_retry );

				return;
			}

			if ( $status >= 500 ) {
				self::record( 'server_error', $status );
				self::maybe_retry( $is_retry );

				return;
			}

			// 4xx — and anything else unexpected — is permanent-quiet: record it
			// and wait for the next regular slot. Never tighten the schedule.
			self::record( 'client_error', $status );
			self::clear_retry( $is_retry );
		} catch ( Throwable $error ) {
			try {
				self::record( 'exception', 0 );
			} catch ( Throwable $while_recording ) {
				// Recording the failure failed too. There is nothing left to try,
				// and nothing may escape the cron path (AD-7).
				unset( $while_recording );
			}
		}
	}

	/**
	 * Sends the report.
	 *
	 * `sslverify` is deliberately left at the WordPress default.
	 *
	 * @param string $key The stored site key.
	 * @return array|\WP_Error The HTTP response, or a transport error.
	 */
	private static function send( $key ) {
		return wp_remote_post(
			self::endpoint_url(),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'   => 'application/json',
					'Accept'         => 'application/json',
					Site_Key::HEADER => $key,
					'User-Agent'     => 'WOptimize-Connector/' . WOPTIMIZE_CONNECTOR_VERSION,
				),
				'body'        => wp_json_encode( Site_Report::build() ),
			)
		);
	}

	/**
	 * Builds the portal endpoint URL.
	 *
	 * @return string The absolute URL of the phone-home endpoint.
	 */
	public static function endpoint_url() {
		return untrailingslashit( WOPTIMIZE_PORTAL_URL ) . self::PATH;
	}

	/**
	 * Reads the recorded outcome of the last attempt.
	 *
	 * @return array Keys `last_attempt_at`, `last_result`, `last_http_status`.
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'last_attempt_at'  => isset( $stored['last_attempt_at'] ) ? (int) $stored['last_attempt_at'] : 0,
			'last_result'      => isset( $stored['last_result'] ) ? (string) $stored['last_result'] : '',
			'last_http_status' => isset( $stored['last_http_status'] ) ? (int) $stored['last_http_status'] : 0,
		);
	}

	/**
	 * Queues the one allowed retry, unless this call already is the retry.
	 *
	 * @param bool $is_retry Whether the caller is the retry itself.
	 * @return void
	 */
	private static function maybe_retry( $is_retry ) {
		if ( $is_retry ) {
			return;
		}

		if ( wp_next_scheduled( self::RETRY_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::RETRY_DELAY, self::RETRY_HOOK );
	}

	/**
	 * Drops a retry that a later regular run has made pointless.
	 *
	 * Without this, a 5xx at one slot could leave a retry queued that then fires
	 * after an `ok`, a 4xx, or a cleared key — which would break the
	 * permanent-quiet promise for the 4xx case (AD-7).
	 *
	 * @param bool $is_retry Whether the caller is the retry itself. A single
	 *                       event has already left the queue by the time it
	 *                       runs, so the retry never needs to clear anything.
	 * @return void
	 */
	private static function clear_retry( $is_retry ) {
		if ( $is_retry ) {
			return;
		}

		wp_unschedule_hook( self::RETRY_HOOK );
	}

	/**
	 * Stores the outcome of an attempt.
	 *
	 * @param string $result      One of `no_key`, `ok`, `client_error`,
	 *                            `server_error`, `transport_error`, `exception`.
	 * @param int    $http_status The HTTP status, or 0 when there was none.
	 * @return void
	 */
	private static function record( $result, $http_status ) {
		update_option(
			self::OPTION,
			array(
				'last_attempt_at'  => time(),
				'last_result'      => $result,
				'last_http_status' => (int) $http_status,
			),
			false
		);
	}

	/**
	 * Queues one report after this plugin updates itself.
	 *
	 * @param mixed $upgrader   The upgrader instance. Unused.
	 * @param mixed $hook_extra What the upgrader processed.
	 * @return void
	 */
	public static function on_upgrade( $upgrader, $hook_extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the upgrader_process_complete action signature.
		if ( ! is_array( $hook_extra ) ) {
			return;
		}

		if ( ! isset( $hook_extra['action'], $hook_extra['type'] ) ) {
			return;
		}

		if ( 'update' !== $hook_extra['action'] || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$updated = array();

		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$updated = $hook_extra['plugins'];
		} elseif ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$updated = array( $hook_extra['plugin'] );
		}

		if ( ! in_array( plugin_basename( WOPTIMIZE_CONNECTOR_FILE ), $updated, true ) ) {
			return;
		}

		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
	}
}
