<?php
/**
 * Shared Brain Monkey setup for every test in the suite.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Boots and tears down Brain Monkey around each test.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Starts Brain Monkey and stubs the passthrough helpers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/\\' );
			}
		);

		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			}
		);

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value ) {
				return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
	}

	/**
	 * Stops Brain Monkey and verifies the Mockery expectations.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();

		parent::tearDown();
	}

	/**
	 * Stubs every WordPress function `Site_Report::build()` reads.
	 *
	 * One fixture site: `https://client.example`, WordPress 6.7.1, Twenty
	 * Twenty-Five 1.2, single site, Europe/Madrid.
	 *
	 * @param array<string, mixed> $transients Site transient values, keyed by name.
	 *                                         Anything absent answers `false`.
	 * @return void
	 */
	protected function stub_site_report( array $transients = array() ): void {
		$theme = new class() {

			/**
			 * The stylesheet directory name.
			 *
			 * @return string
			 */
			public function get_stylesheet() {
				return 'twentytwentyfive';
			}

			/**
			 * Reads a theme header.
			 *
			 * @param string $header The header name.
			 * @return string The header value.
			 */
			public function get( $header ) {
				return 'Name' === $header ? 'Twenty Twenty-Five' : '1.2';
			}
		};

		Functions\when( 'wp_get_theme' )->justReturn( $theme );
		Functions\when( 'site_url' )->justReturn( 'https://client.example' );
		Functions\when( 'home_url' )->justReturn( 'https://client.example' );
		Functions\when( 'rest_url' )->alias(
			static function ( $path ) {
				return 'https://client.example/wp-json/' . $path;
			}
		);
		Functions\when( 'get_bloginfo' )->alias(
			static function ( $show ) {
				return 'version' === $show ? '6.7.1' : 'A Client Site';
			}
		);
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Europe/Madrid' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_transient' )->alias(
			static function ( $name ) use ( $transients ) {
				return array_key_exists( $name, $transients ) ? $transients[ $name ] : false;
			}
		);
	}
}
