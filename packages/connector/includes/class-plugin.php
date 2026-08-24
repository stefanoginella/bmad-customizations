<?php
/**
 * The one boot point of the connector.
 *
 * @package woptimize-connector
 */

namespace WOptimize\Connector;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every piece of the plugin to WordPress.
 *
 * Nothing here talks to the network or the database. `boot()` only registers
 * hooks, so loading the plugin can never break a client site (AD-7).
 */
final class Plugin {

	/**
	 * Registers every hook the connector owns.
	 *
	 * @return void
	 */
	public static function boot() {
		Rest_Controller::boot();
		Phone_Home::boot();
		Settings::boot();
	}
}
