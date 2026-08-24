<?php
/**
 * Plugin Name: WOptimize Connector
 * Plugin URI: https://portal.woptimize.io
 * Description: Connects this site to the WOptimize client portal: REST endpoints the portal calls with the site key, and a daily phone-home. Every remote failure degrades to a silent no-op.
 * Version: 0.1.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author: WOptimize
 * Author URI: https://www.woptimize.io
 * Update URI: https://portal.woptimize.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woptimize-connector
 *
 * @package woptimize-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin version. Must stay equal to the `Version:` header above — the
 * contract test fails otherwise. It is also `SiteReport.connector_version` and
 * the `X-Woptimize-Connector-Version` response header.
 */
define( 'WOPTIMIZE_CONNECTOR_VERSION', '0.1.0' );

/**
 * Absolute path of this file. Used for `plugin_basename()` and the hooks.
 */
define( 'WOPTIMIZE_CONNECTOR_FILE', __FILE__ );

/**
 * Base URL of the WOptimize portal. Overridable from `wp-config.php` — the
 * playground points it at the local portal (AD-18). It is a constant and not a
 * setting on purpose: one less field a human can mistype.
 */
if ( ! defined( 'WOPTIMIZE_PORTAL_URL' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- A wp-config.php override point, deliberately named after the portal and not after this plugin.
	define( 'WOPTIMIZE_PORTAL_URL', 'https://portal.woptimize.io' );
}

require_once __DIR__ . '/includes/class-site-key.php';
require_once __DIR__ . '/includes/class-site-report.php';
require_once __DIR__ . '/includes/class-rest-controller.php';
require_once __DIR__ . '/includes/class-phone-home.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'WOptimize\Connector\Phone_Home', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'WOptimize\Connector\Phone_Home', 'unschedule' ) );

WOptimize\Connector\Plugin::boot();
