<?php
/**
 * PHPUnit bootstrap for the connector unit suite.
 *
 * No WordPress here: Brain Monkey fakes the function layer and `tests/stubs/`
 * supplies the handful of core classes. The AD-7 branches are pure decision
 * logic, so they need seconds, not a database.
 *
 * The plugin constants are read out of the plugin file rather than repeated,
 * so the suite can never disagree with the shipped version.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

$woptimize_connector_root = dirname( __DIR__ );

require_once $woptimize_connector_root . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wp-classes.php';

define( 'ABSPATH', $woptimize_connector_root . '/' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );

define( 'WOPTIMIZE_CONNECTOR_PLUGIN_FILE_PATH', $woptimize_connector_root . '/woptimize-connector.php' );

$woptimize_connector_source = (string) file_get_contents( WOPTIMIZE_CONNECTOR_PLUGIN_FILE_PATH );

if ( 1 !== preg_match(
	'/define\(\s*\'WOPTIMIZE_CONNECTOR_VERSION\'\s*,\s*\'([^\']+)\'\s*\)/',
	$woptimize_connector_source,
	$woptimize_connector_matches
) ) {
	fwrite( STDERR, "Could not read WOPTIMIZE_CONNECTOR_VERSION from woptimize-connector.php.\n" );
	exit( 1 );
}

define( 'WOPTIMIZE_CONNECTOR_VERSION', $woptimize_connector_matches[1] );
define( 'WOPTIMIZE_CONNECTOR_FILE', WOPTIMIZE_CONNECTOR_PLUGIN_FILE_PATH );

if ( 1 !== preg_match(
	'/define\(\s*\'WOPTIMIZE_PORTAL_URL\'\s*,\s*\'([^\']+)\'\s*\)/',
	$woptimize_connector_source,
	$woptimize_connector_matches
) ) {
	fwrite( STDERR, "Could not read the WOPTIMIZE_PORTAL_URL default from woptimize-connector.php.\n" );
	exit( 1 );
}

define( 'WOPTIMIZE_PORTAL_URL', $woptimize_connector_matches[1] );

require_once $woptimize_connector_root . '/includes/class-site-key.php';
require_once $woptimize_connector_root . '/includes/class-site-report.php';
require_once $woptimize_connector_root . '/includes/class-rest-controller.php';
require_once $woptimize_connector_root . '/includes/class-phone-home.php';
require_once $woptimize_connector_root . '/includes/class-settings.php';
require_once $woptimize_connector_root . '/includes/class-plugin.php';

require_once __DIR__ . '/TestCase.php';
