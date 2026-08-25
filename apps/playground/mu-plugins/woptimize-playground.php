<?php
/**
 * Plugin Name: WOptimize Playground
 * Description: The three local-only facts the integration suite needs: which portal the connector reports to, that only WP-CLI fires a phone-home, and where the DDEV certificate authority lives.
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author: WOptimize
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package woptimize-playground
 *
 * This file is a must-use plugin so it loads before the connector, which
 * guards its own `WOPTIMIZE_PORTAL_URL` define — first definition wins.
 *
 * It exists only in `apps/playground`. Nothing here ships to a client site.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The portal this playground reports to.
 *
 * `apps/playground/.ddev/config.yaml` sets the environment variable; a single
 * test overrides it per WP-CLI call to play an unreachable portal. The default
 * is the local portal project — the only non-production value in the repo.
 */
if ( ! defined( 'WOPTIMIZE_PORTAL_URL' ) ) {
	$woptimize_playground_portal = getenv( 'WOPTIMIZE_PORTAL_URL' );

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The connector's documented override point, named after the portal and not after this plugin.
	define(
		'WOPTIMIZE_PORTAL_URL',
		is_string( $woptimize_playground_portal ) && '' !== $woptimize_playground_portal
			? $woptimize_playground_portal
			: 'https://portal.woptimize.ddev.site'
	);

	unset( $woptimize_playground_portal );
}

/**
 * Only WP-CLI fires a phone-home here.
 *
 * A browser hit, or the portal's own `site:ping`, would otherwise spawn a
 * background cron run in the middle of a scenario and overwrite the outcome a
 * test is about to read. WP-CLI runs events regardless of this constant.
 */
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- A WordPress core constant.
	define( 'DISABLE_WP_CRON', true );
}

/**
 * Teaches the WordPress HTTP API about the DDEV certificate authority.
 *
 * cURL inside the container trusts mkcert through the system store, but
 * WordPress ships its own CA bundle and hands it to cURL explicitly, so a
 * request to `https://portal.woptimize.ddev.site` fails with error 60. Pointing
 * `sslcertificates` at the mkcert root fixes it without ever switching
 * verification off — `sslverify => false` would make the suite prove nothing
 * about TLS.
 *
 * Only for `*.ddev.site`. The mkcert root signs nothing else, so forcing it on
 * every request would break the ones that must reach the public internet —
 * `wp core update --force` downloading from wordpress.org, for one, which boots
 * WordPress and therefore loads this file.
 *
 * @param array  $args Arguments for the request.
 * @param string $url  The request URL.
 * @return array The arguments, with the DDEV root CA for DDEV hosts.
 */
function woptimize_playground_use_ddev_ca( $args, $url ) {
	$root_ca = '/mnt/ddev-global-cache/mkcert/rootCA.pem';
	$host    = (string) wp_parse_url( (string) $url, PHP_URL_HOST );

	if ( '' === $host || ! is_readable( $root_ca ) ) {
		return $args;
	}

	if ( 'ddev.site' !== $host && ! str_ends_with( $host, '.ddev.site' ) ) {
		return $args;
	}

	$args['sslcertificates'] = $root_ca;

	return $args;
}

add_filter( 'http_request_args', 'woptimize_playground_use_ddev_ca', 10, 2 );
