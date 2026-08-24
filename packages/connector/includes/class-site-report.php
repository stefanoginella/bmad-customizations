<?php
/**
 * The one writer of the SiteReport payload.
 *
 * @package woptimize-connector
 */

namespace WOptimize\Connector;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `SiteReport` shape defined in `openapi.yaml`.
 *
 * One writer, two consumers: the `GET /status` response body and the
 * `POST /phone-home` request body are the same array. Changing a field here
 * means changing the contract file first (AD-4).
 */
final class Site_Report {

	/**
	 * Collects everything the portal is told about this site.
	 *
	 * @return array The `SiteReport` payload.
	 */
	public static function build() {
		$theme = wp_get_theme();

		return array(
			'connector_version' => WOPTIMIZE_CONNECTOR_VERSION,
			'site_url'          => site_url(),
			'home_url'          => home_url(),
			'rest_base'         => rest_url( Rest_Controller::REST_NAMESPACE ),
			'site_name'         => (string) get_bloginfo( 'name' ),
			'wp_version'        => (string) get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'locale'            => get_locale(),
			'timezone'          => wp_timezone_string(),
			'multisite'         => is_multisite(),
			'theme'             => array(
				'slug'    => (string) $theme->get_stylesheet(),
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			),
			'updates'           => array(
				'wordpress' => self::core_update_count(),
				'plugins'   => self::transient_response_count( 'update_plugins' ),
				'themes'    => self::transient_response_count( 'update_themes' ),
			),
		);
	}

	/**
	 * Counts the core updates WordPress is offering.
	 *
	 * `wp_get_update_data()` is capability-gated and returns zeroes for a REST
	 * request or a cron run, so the transients are read directly.
	 *
	 * @return int Number of core updates whose response is `upgrade`.
	 */
	private static function core_update_count() {
		$transient = get_site_transient( 'update_core' );

		if ( ! is_object( $transient ) || ! isset( $transient->updates ) || ! is_array( $transient->updates ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $transient->updates as $update ) {
			if ( is_object( $update ) && isset( $update->response ) && 'upgrade' === $update->response ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Counts the pending items in an update site transient.
	 *
	 * @param string $transient_name Either `update_plugins` or `update_themes`.
	 * @return int Number of items with a pending update.
	 */
	private static function transient_response_count( $transient_name ) {
		$transient = get_site_transient( $transient_name );

		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return 0;
		}

		return count( $transient->response );
	}
}
