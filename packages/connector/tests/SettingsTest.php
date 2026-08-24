<?php
/**
 * The settings screen: what it accepts, what it refuses, what it shows.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

use Brain\Monkey\Functions;
use WOptimize\Connector\Phone_Home;
use WOptimize\Connector\Settings;
use WOptimize\Connector\Site_Key;

/**
 * Covers Settings.
 */
final class SettingsTest extends TestCase {

	/**
	 * A well-formed key: 40 alphanumeric characters.
	 *
	 * @var string
	 */
	private const VALID_KEY = 'aB3dE5gH7jK9mN1pQ3sT5vW7yZ9bD1fH3jL5nP7r';

	/**
	 * The key the site already had before the form was submitted.
	 *
	 * @var string
	 */
	private const OLD_KEY = 'zY9xW7vU5tS3rQ1pO9nM7lK5jI3hG1fE9dC7bA5z';

	/**
	 * Settings errors raised during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $errors = array();

	/**
	 * A well-formed key is stored as pasted.
	 *
	 * @return void
	 */
	public function test_a_valid_key_is_stored(): void {
		$this->stub_stored_key( self::OLD_KEY );

		$this->assertSame( self::VALID_KEY, Settings::sanitize_site_key( self::VALID_KEY ) );
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * Surrounding whitespace from a copy-paste is trimmed, not refused.
	 *
	 * @return void
	 */
	public function test_a_pasted_key_is_trimmed(): void {
		$this->stub_stored_key( self::OLD_KEY );

		$this->assertSame( self::VALID_KEY, Settings::sanitize_site_key( "  \n" . self::VALID_KEY . " \t" ) );
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * A malformed key keeps the old value and explains the format.
	 *
	 * @dataProvider provide_malformed_keys
	 *
	 * @param mixed  $submitted The submitted value.
	 * @param string $why       What the case is about.
	 * @return void
	 */
	public function test_a_malformed_key_keeps_the_old_value( $submitted, string $why ): void {
		$this->stub_stored_key( self::OLD_KEY );

		$this->assertSame( self::OLD_KEY, Settings::sanitize_site_key( $submitted ), $why );
		$this->assertCount( 1, $this->errors );
		$this->assertSame( Site_Key::OPTION, $this->errors[0]['setting'] );
		$this->assertSame( 'error', $this->errors[0]['type'] );
		$this->assertStringContainsString( (string) Site_Key::LENGTH, $this->errors[0]['message'] );
	}

	/**
	 * Ways a paste can go wrong.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function provide_malformed_keys(): array {
		return array(
			'one character short' => array( substr( self::VALID_KEY, 0, 39 ), 'A 39-character key must be refused.' ),
			'contains a symbol'   => array( substr( self::VALID_KEY, 0, 39 ) . '-', 'Symbols are not part of the format.' ),
			'a whole sentence'    => array( 'paste your key here', 'Prose must be refused.' ),
			'not a string'        => array( array( self::VALID_KEY ), 'A non-string must be refused.' ),
		);
	}

	/**
	 * An empty field disconnects the site on purpose — that is not an error.
	 *
	 * @return void
	 */
	public function test_an_empty_field_clears_the_key(): void {
		$this->stub_stored_key( self::OLD_KEY );

		$this->assertSame( '', Settings::sanitize_site_key( '' ) );
		$this->assertSame( '', Settings::sanitize_site_key( '   ' ) );
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * Re-saving the key it already has still reports to the portal.
	 *
	 * `update_option()` returns early for an identical value, so the
	 * `update_option_*` action never fires. Pressing Save on an unchanged key is
	 * how a human retries a connection that failed, so that case must not go
	 * quiet.
	 *
	 * @return void
	 */
	public function test_resaving_an_unchanged_key_reports_at_once(): void {
		$this->stub_stored_key( self::VALID_KEY );
		$this->stub_phone_home();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array( 'response' => array( 'code' => 200 ) ) );

		$this->assertSame( self::VALID_KEY, Settings::sanitize_site_key( self::VALID_KEY ) );
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * A changed key is left to the option hook — reporting here as well would
	 * send two identical reports for one save.
	 *
	 * @return void
	 */
	public function test_a_changed_key_leaves_the_report_to_the_option_hook(): void {
		$this->stub_stored_key( self::OLD_KEY );
		$this->stub_phone_home();

		Functions\expect( 'wp_remote_post' )->never();

		$this->assertSame( self::VALID_KEY, Settings::sanitize_site_key( self::VALID_KEY ) );
	}

	/**
	 * The setting is registered with this plugin's sanitize callback.
	 *
	 * @return void
	 */
	public function test_register_wires_the_setting_and_the_field(): void {
		$registered = array();

		Functions\when( 'register_setting' )->alias(
			static function ( $group, $option, $args ) use ( &$registered ) {
				$registered = array(
					'group'  => $group,
					'option' => $option,
					'args'   => $args,
				);
			}
		);
		Functions\expect( 'add_settings_section' )->once();
		Functions\expect( 'add_settings_field' )->once();

		Settings::register();

		$this->assertSame( Settings::GROUP, $registered['group'] );
		$this->assertSame( Site_Key::OPTION, $registered['option'] );
		$this->assertSame(
			array( Settings::class, 'sanitize_site_key' ),
			$registered['args']['sanitize_callback']
		);
		$this->assertFalse( $registered['args']['show_in_rest'], 'The key is a credential — never over REST.' );
	}

	/**
	 * The page sits under Settings and needs `manage_options`.
	 *
	 * @return void
	 */
	public function test_the_page_requires_manage_options(): void {
		$added = array();

		Functions\when( 'add_options_page' )->alias(
			static function ( $page_title, $menu_title, $capability, $slug ) use ( &$added ) {
				$added = array(
					'page_title' => $page_title,
					'menu_title' => $menu_title,
					'capability' => $capability,
					'slug'       => $slug,
				);
			}
		);

		Settings::add_page();

		$this->assertSame( 'manage_options', $added['capability'] );
		$this->assertSame( Settings::PAGE, $added['slug'] );
	}

	/**
	 * A user without the capability sees nothing at all.
	 *
	 * @return void
	 */
	public function test_render_prints_nothing_without_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', $this->capture_render() );
	}

	/**
	 * The page shows the form and the outcome of the last report.
	 *
	 * @return void
	 */
	public function test_render_shows_the_form_and_the_last_result(): void {
		$this->stub_render_environment();

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) {
				if ( Phone_Home::OPTION === $name ) {
					return array(
						'last_attempt_at'  => 1755000000,
						'last_result'      => 'server_error',
						'last_http_status' => 503,
					);
				}

				return Site_Key::OPTION === $name ? self::VALID_KEY : $default_value;
			}
		);

		$output = $this->capture_render();

		$this->assertStringContainsString( 'action="options.php"', $output );
		$this->assertStringContainsString( 'server_error', $output );
		$this->assertStringContainsString( '503', $output );
		$this->assertStringContainsString( '2026-01-01 00:00:00', $output );
	}

	/**
	 * Before the first attempt the page says so, rather than showing zeroes.
	 *
	 * @return void
	 */
	public function test_render_reports_that_nothing_has_been_sent_yet(): void {
		$this->stub_render_environment();

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) {
				return Site_Key::OPTION === $name ? '' : $default_value;
			}
		);

		$this->assertStringContainsString( 'No report has been attempted yet.', $this->capture_render() );
	}

	/**
	 * The key field is a password input, so it is not shoulder-readable.
	 *
	 * @return void
	 */
	public function test_the_key_field_is_a_password_input(): void {
		$this->stub_stored_key( self::VALID_KEY );

		ob_start();
		Settings::render_site_key_field();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $output );
		$this->assertStringContainsString( 'name="' . Site_Key::OPTION . '"', $output );
		$this->assertStringContainsString( self::VALID_KEY, $output );
	}

	/**
	 * Stubs the option read and captures any settings error raised.
	 *
	 * @param string $stored The key already in the option.
	 * @return void
	 */
	private function stub_stored_key( string $stored ): void {
		$this->errors = array();

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( $stored ) {
				return Site_Key::OPTION === $name ? $stored : $default_value;
			}
		);

		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code, $message, $type = 'error' ) {
				$this->errors[] = array(
					'setting' => $setting,
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				);
			}
		);
	}

	/**
	 * Stubs everything a phone-home touches, minus `wp_remote_post()` — each
	 * test says for itself whether that is expected to fire.
	 *
	 * @return void
	 */
	private function stub_phone_home(): void {
		$this->stub_site_report();

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_unschedule_hook' )->justReturn( 1 );
	}

	/**
	 * Stubs the admin functions the page calls.
	 *
	 * @return void
	 */
	private function stub_render_environment(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_admin_page_title' )->justReturn( 'WOptimize' );
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'do_settings_sections' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_date' )->justReturn( '2026-01-01 00:00:00' );
	}

	/**
	 * Renders the page into a string.
	 *
	 * @return string The page markup.
	 */
	private function capture_render(): string {
		ob_start();
		Settings::render();

		return (string) ob_get_clean();
	}
}
