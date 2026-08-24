<?php
/**
 * The Settings -> WOptimize screen: one field, one status line.
 *
 * @package woptimize-connector
 */

namespace WOptimize\Connector;

defined( 'ABSPATH' ) || exit;

/**
 * The only place a human touches the connector.
 *
 * The screen accepts a portal-issued key and shows the outcome of the last
 * phone-home. It never generates a key, and it is the only surface allowed to
 * report a remote failure (AD-7, AD-16).
 */
final class Settings {

	/**
	 * The admin page slug.
	 *
	 * @var string
	 */
	const PAGE = 'woptimize-connector';

	/**
	 * The settings group registered with the Settings API.
	 *
	 * @var string
	 */
	const GROUP = 'woptimize_connector';

	/**
	 * The settings section id.
	 *
	 * @var string
	 */
	const SECTION = 'woptimize_connector_portal';

	/**
	 * The capability required to see or change anything here.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hooks the screen into the admin.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Adds the page under Settings.
	 *
	 * @return void
	 */
	public static function add_page() {
		add_options_page(
			__( 'WOptimize', 'woptimize-connector' ),
			__( 'WOptimize', 'woptimize-connector' ),
			self::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Registers the setting, its section, and its one field.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			Site_Key::OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_site_key' ),
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Portal connection', 'woptimize-connector' ),
			array( __CLASS__, 'render_section' ),
			self::PAGE
		);

		add_settings_field(
			Site_Key::OPTION,
			__( 'Site key', 'woptimize-connector' ),
			array( __CLASS__, 'render_site_key_field' ),
			self::PAGE,
			self::SECTION,
			array( 'label_for' => Site_Key::OPTION )
		);
	}

	/**
	 * Validates a pasted key.
	 *
	 * An empty field clears the key. A malformed one keeps the stored value and
	 * explains the format — a bad paste must never silently disconnect a site.
	 *
	 * @param mixed $value The submitted value.
	 * @return string The value to store.
	 */
	public static function sanitize_site_key( $value ) {
		if ( is_string( $value ) ) {
			$candidate = trim( $value );

			// Only a deliberately emptied field clears the key.
			if ( '' === $candidate ) {
				return '';
			}

			if ( Site_Key::is_valid_format( $candidate ) ) {
				/*
				 * A changed key reaches the portal through
				 * `update_option_woptimize_connector_site_key`. An unchanged one
				 * never does: `update_option()` returns early when the value is
				 * identical, so that action does not fire. Pressing Save on the
				 * same key is exactly how a human retries a connection that
				 * failed, so report from here instead of losing that case.
				 */
				if ( Site_Key::get() === $candidate ) {
					Phone_Home::run_scheduled();
				}

				return $candidate;
			}
		}

		add_settings_error(
			Site_Key::OPTION,
			'woptimize_connector_invalid_site_key',
			sprintf(
				/* translators: %d: the required key length, in characters. */
				__( 'The site key must be exactly %d letters or digits, as issued by the WOptimize portal. The stored key was left unchanged.', 'woptimize-connector' ),
				Site_Key::LENGTH
			),
			'error'
		);

		return Site_Key::get();
	}

	/**
	 * Explains what the section is for.
	 *
	 * @return void
	 */
	public static function render_section() {
		echo '<p>';
		echo esc_html__( 'Paste the site key issued by the WOptimize portal. The portal creates every key; this plugin never generates one.', 'woptimize-connector' );
		echo '</p>';
	}

	/**
	 * Renders the key field.
	 *
	 * @return void
	 */
	public static function render_site_key_field() {
		printf(
			'<input type="password" class="regular-text" id="%1$s" name="%1$s" value="%2$s" autocomplete="off" spellcheck="false" />',
			esc_attr( Site_Key::OPTION ),
			esc_attr( Site_Key::get() )
		);

		echo '<p class="description">';
		printf(
			/* translators: %d: the required key length, in characters. */
			esc_html__( '%d letters or digits. Leave empty to disconnect this site — the connector then does nothing at all.', 'woptimize-connector' ),
			(int) Site_Key::LENGTH
		);
		echo '</p>';
	}

	/**
	 * Renders the whole page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';

		/*
		 * No settings_errors() call here on purpose. The page sits under
		 * Settings, so `admin-header.php` has already loaded `options-head.php`,
		 * which prints them. Calling it again would show every error twice.
		 */

		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';

		self::render_last_result();

		echo '</div>';
	}

	/**
	 * Prints the outcome of the last phone-home.
	 *
	 * This page is the only place a remote failure is ever shown (AD-7).
	 *
	 * @return void
	 */
	private static function render_last_result() {
		$state = Phone_Home::state();

		echo '<h2>' . esc_html__( 'Last report to the portal', 'woptimize-connector' ) . '</h2>';
		echo '<p>';

		if ( 0 === $state['last_attempt_at'] || '' === $state['last_result'] ) {
			echo esc_html__( 'No report has been attempted yet.', 'woptimize-connector' );
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: a formatted date and time, 2: an outcome such as "ok" or "server_error", 3: an HTTP status code, or 0 when there was none. */
					__( '%1$s — result: %2$s (HTTP %3$d)', 'woptimize-connector' ),
					wp_date( 'Y-m-d H:i:s', $state['last_attempt_at'] ),
					$state['last_result'],
					$state['last_http_status']
				)
			);
		}

		echo '</p>';
	}
}
