<?php
/**
 * Removes every trace of the connector on uninstall.
 *
 * Offboarding is uninstall — there is no license system anywhere (AD-7). This
 * file runs without the plugin loaded, so the option and hook names are spelled
 * out here; `ContractTest` checks they still match the class constants.
 *
 * @package woptimize-connector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woptimize_connector_site_key' );
delete_option( 'woptimize_connector_phone_home' );

wp_unschedule_hook( 'woptimize_connector_phone_home' );
wp_unschedule_hook( 'woptimize_connector_phone_home_retry' );
