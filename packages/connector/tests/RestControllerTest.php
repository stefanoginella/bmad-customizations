<?php
/**
 * The connector-hosted routes: ping, status, auth, and the version header.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

use Brain\Monkey\Functions;
use WOptimize\Connector\Rest_Controller;
use WOptimize\Connector\Site_Key;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Covers Rest_Controller.
 */
final class RestControllerTest extends TestCase {

	/**
	 * A well-formed key: 40 alphanumeric characters.
	 *
	 * @var string
	 */
	private const VALID_KEY = 'aB3dE5gH7jK9mN1pQ3sT5vW7yZ9bD1fH3jL5nP7r';

	/**
	 * Both routes register under the versioned namespace, key-gated.
	 *
	 * @return void
	 */
	public function test_register_routes_registers_ping_and_status(): void {
		$registered = array();

		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route, $args ) use ( &$registered ) {
				$registered[] = array(
					'namespace' => $route_namespace,
					'route'     => $route,
					'args'      => $args,
				);

				return true;
			}
		);

		( new Rest_Controller() )->register_routes();

		$this->assertCount( 2, $registered );
		$this->assertSame( array( '/ping', '/status' ), array_column( $registered, 'route' ) );

		foreach ( $registered as $entry ) {
			$this->assertSame( Rest_Controller::REST_NAMESPACE, $entry['namespace'] );
			$this->assertSame( WP_REST_Server::READABLE, $entry['args'][0]['methods'] );
			$this->assertSame(
				'check_key',
				$entry['args'][0]['permission_callback'][1],
				'Every route must be gated by the same key check (AD-5).'
			);
		}
	}

	/**
	 * Ping answers 200 with exactly `{"ok":true}` — no version in the body.
	 *
	 * @return void
	 */
	public function test_ping_returns_ok(): void {
		$response = ( new Rest_Controller() )->ping( new WP_REST_Request( 'GET', '/woptimize/v1/ping' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'ok' => true ), $response->get_data() );
	}

	/**
	 * Status answers 200 with the whole SiteReport.
	 *
	 * @return void
	 */
	public function test_status_returns_the_site_report(): void {
		$this->stub_site_report();

		$response = ( new Rest_Controller() )->status( new WP_REST_Request( 'GET', '/woptimize/v1/status' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame(
			array(
				'connector_version',
				'site_url',
				'home_url',
				'rest_base',
				'site_name',
				'wp_version',
				'php_version',
				'locale',
				'timezone',
				'multisite',
				'theme',
				'updates',
			),
			array_keys( $data )
		);

		$this->assertSame( WOPTIMIZE_CONNECTOR_VERSION, $data['connector_version'] );
		$this->assertSame( 'https://client.example/wp-json/woptimize/v1', $data['rest_base'] );
		$this->assertSame( array( 'slug', 'name', 'version' ), array_keys( $data['theme'] ) );
		$this->assertSame( array( 'wordpress', 'plugins', 'themes' ), array_keys( $data['updates'] ) );
		$this->assertFalse( $data['multisite'] );
	}

	/**
	 * The right key passes the gate.
	 *
	 * @return void
	 */
	public function test_check_key_accepts_the_stored_key(): void {
		Functions\when( 'get_option' )->justReturn( self::VALID_KEY );

		$request = new WP_REST_Request( 'GET', '/woptimize/v1/ping' );
		$request->set_header( Site_Key::HEADER, self::VALID_KEY );

		$this->assertTrue( ( new Rest_Controller() )->check_key( $request ) );
	}

	/**
	 * Wrong key, missing header, and no stored key all give one silent 401.
	 *
	 * @dataProvider provide_unauthorized_requests
	 *
	 * @param string      $stored    The key in the option.
	 * @param string|null $presented The header value, or null for no header.
	 * @return void
	 */
	public function test_check_key_rejects_bad_auth( string $stored, ?string $presented ): void {
		Functions\when( 'get_option' )->justReturn( $stored );

		$request = new WP_REST_Request( 'GET', '/woptimize/v1/status' );

		if ( null !== $presented ) {
			$request->set_header( Site_Key::HEADER, $presented );
		}

		$error = ( new Rest_Controller() )->check_key( $request );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'woptimize_unauthorized', $error->get_error_code() );
		$this->assertSame( array( 'status' => 401 ), $error->get_error_data() );
		$this->assertNotSame( '', $error->get_error_message() );
	}

	/**
	 * The three ways auth can fail.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function provide_unauthorized_requests(): array {
		return array(
			'wrong key'      => array( self::VALID_KEY, strrev( self::VALID_KEY ) ),
			'missing header' => array( self::VALID_KEY, null ),
			'empty header'   => array( self::VALID_KEY, '' ),
			'no stored key'  => array( '', self::VALID_KEY ),
		);
	}

	/**
	 * The header is canonicalized the way WordPress does it.
	 *
	 * @return void
	 */
	public function test_check_key_reads_the_header_case_insensitively(): void {
		Functions\when( 'get_option' )->justReturn( self::VALID_KEY );

		$request = new WP_REST_Request( 'GET', '/woptimize/v1/ping' );
		$request->set_header( 'x-woptimize-site-key', self::VALID_KEY );

		$this->assertTrue( ( new Rest_Controller() )->check_key( $request ) );
	}

	/**
	 * Every response under the namespace carries the version header.
	 *
	 * @dataProvider provide_namespaced_routes
	 *
	 * @param string $route  The dispatched route.
	 * @param int    $status The response status.
	 * @return void
	 */
	public function test_version_header_is_added_in_the_namespace( string $route, int $status ): void {
		$response = Rest_Controller::add_version_header(
			new WP_REST_Response( array(), $status ),
			null,
			new WP_REST_Request( 'GET', $route )
		);

		$this->assertSame(
			WOPTIMIZE_CONNECTOR_VERSION,
			$response->get_headers()[ Rest_Controller::VERSION_HEADER ]
		);
	}

	/**
	 * Routes that must carry the header, including the 401 core builds.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function provide_namespaced_routes(): array {
		return array(
			'ping 200'        => array( '/woptimize/v1/ping', 200 ),
			'status 200'      => array( '/woptimize/v1/status', 200 ),
			'unauthorized'    => array( '/woptimize/v1/status', 401 ),
			'namespace index' => array( '/woptimize/v1', 200 ),
		);
	}

	/**
	 * Other plugins' routes are left alone.
	 *
	 * @return void
	 */
	public function test_version_header_is_not_added_outside_the_namespace(): void {
		$response = Rest_Controller::add_version_header(
			new WP_REST_Response( array(), 200 ),
			null,
			new WP_REST_Request( 'GET', '/wp/v2/posts' )
		);

		$this->assertSame( array(), $response->get_headers() );
	}

	/**
	 * A non-response result passes straight through.
	 *
	 * @return void
	 */
	public function test_version_header_leaves_non_responses_untouched(): void {
		$this->assertNull(
			Rest_Controller::add_version_header( null, null, new WP_REST_Request( 'GET', '/woptimize/v1/ping' ) )
		);
	}
}
