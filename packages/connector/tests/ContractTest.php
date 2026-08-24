<?php
/**
 * `openapi.yaml` is the source of truth (AD-4). This test is the guard that
 * the code and the contract file still describe the same thing.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

use Brain\Monkey\Functions;
use Symfony\Component\Yaml\Yaml;
use WOptimize\Connector\Phone_Home;
use WOptimize\Connector\Rest_Controller;
use WOptimize\Connector\Site_Key;
use WOptimize\Connector\Site_Report;

/**
 * Covers packages/connector/openapi.yaml against the plugin.
 */
final class ContractTest extends TestCase {

	/**
	 * The parsed contract file.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $contract = null;

	/**
	 * The contract file pins the OpenAPI version from the Stack table.
	 *
	 * @return void
	 */
	public function test_openapi_version(): void {
		$this->assertSame( '3.1.0', $this->contract()['openapi'] );
	}

	/**
	 * `info.version` is the connector's MAJOR.MINOR — Karin's rule reads it
	 * to decide which contract a portal release still has to support (AD-8).
	 *
	 * @return void
	 */
	public function test_info_version_is_the_connector_minor(): void {
		$parts = explode( '.', WOPTIMIZE_CONNECTOR_VERSION );

		$this->assertCount( 3, $parts, 'The connector version is semver.' );
		$this->assertSame( $parts[0] . '.' . $parts[1], (string) $this->contract()['info']['version'] );
	}

	/**
	 * The connector-hosted paths are exactly the routes the plugin registers.
	 *
	 * @return void
	 */
	public function test_connector_paths_match_the_registered_routes(): void {
		$registered = array();

		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route ) use ( &$registered ) {
				$registered[] = $route;

				return true;
			}
		);

		( new Rest_Controller() )->register_routes();

		sort( $registered );
		$documented = $this->paths_hosted_by( '{rest_base}' );
		sort( $documented );

		$this->assertSame( $documented, $registered );
	}

	/**
	 * The portal-hosted path is exactly the URL the connector posts to.
	 *
	 * @return void
	 */
	public function test_portal_path_matches_the_phone_home_url(): void {
		$documented = $this->paths_hosted_by( '{portal}' );

		$this->assertSame( array( '/phone-home' ), $documented );

		$server = $this->contract()['paths']['/phone-home']['servers'][0];

		$this->assertSame( '{portal}/api/connector/v1', $server['url'] );
		$this->assertSame(
			WOPTIMIZE_PORTAL_URL,
			$server['variables']['portal']['default'],
			'The documented default portal must equal the plugin constant.'
		);
		$this->assertSame(
			str_replace( '{portal}', WOPTIMIZE_PORTAL_URL, $server['url'] ) . '/phone-home',
			Phone_Home::endpoint_url()
		);
	}

	/**
	 * Every path declares its own server: the file covers both directions.
	 *
	 * @return void
	 */
	public function test_every_path_declares_its_direction(): void {
		foreach ( $this->contract()['paths'] as $path => $definition ) {
			$this->assertArrayHasKey( 'servers', $definition, $path . ' must say who hosts it.' );
			$this->assertNotEmpty( $definition['servers'][0]['url'], $path . ' needs a server URL.' );
		}
	}

	/**
	 * The auth header in the file is the one the code reads and sends (AD-5).
	 *
	 * @return void
	 */
	public function test_security_scheme_uses_the_site_key_header(): void {
		$scheme = $this->contract()['components']['securitySchemes']['SiteKey'];

		$this->assertSame( 'apiKey', $scheme['type'] );
		$this->assertSame( 'header', $scheme['in'] );
		$this->assertSame( Site_Key::HEADER, $scheme['name'] );
		$this->assertSame( array( array( 'SiteKey' => array() ) ), $this->contract()['security'] );
	}

	/**
	 * The only response header in the file is the version header the code adds.
	 *
	 * @return void
	 */
	public function test_response_header_is_the_connector_version_header(): void {
		$contract = $this->contract();

		$names = array_merge(
			$this->collect_response_header_names( $contract['paths'] ),
			$this->collect_response_header_names( $contract['components']['responses'] )
		);

		$this->assertNotEmpty( $names, 'The contract must document the version header.' );
		$this->assertSame( array( Rest_Controller::VERSION_HEADER ), array_values( array_unique( $names ) ) );
		$this->assertArrayHasKey( 'ConnectorVersion', $contract['components']['headers'] );
	}

	/**
	 * Every schema the story names is present.
	 *
	 * @return void
	 */
	public function test_the_named_schemas_exist(): void {
		$this->assertSame(
			array( 'Ping', 'PhoneHomeAck', 'SiteReport', 'WpRestError', 'LaravelError' ),
			array_keys( $this->contract()['components']['schemas'] )
		);
	}

	/**
	 * One SiteReport schema serves both directions, and it matches the array
	 * the plugin actually builds.
	 *
	 * @return void
	 */
	public function test_site_report_schema_matches_the_built_report(): void {
		$contract = $this->contract();

		$this->assertSame(
			'#/components/schemas/SiteReport',
			$contract['paths']['/status']['get']['responses']['200']['content']['application/json']['schema']['$ref']
		);
		$this->assertSame(
			'#/components/schemas/SiteReport',
			$contract['paths']['/phone-home']['post']['requestBody']['content']['application/json']['schema']['$ref']
		);

		$schema = $contract['components']['schemas']['SiteReport'];

		$this->stub_site_report();

		$built = Site_Report::build();

		$this->assertSame( array_keys( $schema['properties'] ), array_keys( $built ) );
		$this->assertSame( $schema['required'], array_keys( $built ) );
		$this->assertSame(
			array_keys( $schema['properties']['theme']['properties'] ),
			array_keys( $built['theme'] )
		);
		$this->assertSame(
			array_keys( $schema['properties']['updates']['properties'] ),
			array_keys( $built['updates'] )
		);
	}

	/**
	 * The error envelopes are the framework defaults, never a custom shape.
	 *
	 * @return void
	 */
	public function test_error_envelopes(): void {
		$schemas = $this->contract()['components']['schemas'];

		$this->assertSame( array( 'code', 'message', 'data' ), $schemas['WpRestError']['required'] );
		$this->assertSame( array( 'status' ), $schemas['WpRestError']['properties']['data']['required'] );
		$this->assertSame( array( 'message' ), $schemas['LaravelError']['required'] );
	}

	/**
	 * `ok` can only ever be `true`. A failure is an error envelope, so a body
	 * with `ok: false` is off-contract, not a documented outcome.
	 *
	 * @return void
	 */
	public function test_ok_is_pinned_to_true(): void {
		$schemas = $this->contract()['components']['schemas'];

		$this->assertSame( array( true ), $schemas['Ping']['properties']['ok']['enum'] );
		$this->assertSame( array( true ), $schemas['PhoneHomeAck']['properties']['ok']['enum'] );
	}

	/**
	 * Nothing in the file is a closed schema.
	 *
	 * Karin's rule (AD-8) has the portal serving the current and the previous
	 * connector minor, with the connector shipping first — so a reader always
	 * has to tolerate fields a newer counterpart added. One
	 * `additionalProperties: false` would turn every additive minor into a
	 * breaking change.
	 *
	 * @return void
	 */
	public function test_no_schema_is_closed(): void {
		$closed = array();

		$this->walk(
			$this->contract(),
			static function ( $key, $value ) use ( &$closed ): void {
				if ( 'additionalProperties' === $key && false === $value ) {
					$closed[] = 'additionalProperties: false';
				}
			}
		);

		$this->assertSame( array(), $closed );
	}

	/**
	 * The portal's 5xx is a documented outcome with the AD-7 answer attached.
	 *
	 * @return void
	 */
	public function test_phone_home_documents_the_portal_being_down(): void {
		$responses = $this->contract()['paths']['/phone-home']['post']['responses'];

		$this->assertArrayHasKey( '5XX', $responses );
		$this->assertSame(
			'#/components/schemas/LaravelError',
			$responses['5XX']['content']['application/json']['schema']['$ref']
		);
		$this->assertStringContainsString( '15 minutes', $responses['5XX']['description'] );
		$this->assertStringContainsString( 'never queues another', $responses['5XX']['description'] );
	}

	/**
	 * Story 9 adds update-check and download paths — they are not here yet.
	 *
	 * @return void
	 */
	public function test_no_update_delivery_paths_yet(): void {
		$paths = array_keys( $this->contract()['paths'] );

		$this->assertSame( array( '/ping', '/status', '/phone-home' ), $paths );
	}

	/**
	 * The file stays 3.0.3-portable, so story 6 can flip one line if the
	 * validator it picks only speaks 3.0 (spine, Deferred).
	 *
	 * @return void
	 */
	public function test_the_file_is_portable_to_openapi_303(): void {
		$this->assertArrayNotHasKey( 'webhooks', $this->contract(), '`webhooks` is 3.1 only.' );

		// Keywords that exist only in 3.1 (or only in 3.0), so a version flip
		// would silently change what the file means.
		$forbidden = array(
			'nullable',              // 3.0 only; 3.1 uses a type array.
			'const',                 // JSON Schema 2020-12, absent from 3.0.
			'$schema',               // 3.1 only.
			'$comment',              // JSON Schema 2020-12, absent from 3.0.
			'examples',              // Schema-level; 3.0 has singular `example`.
			'jsonSchemaDialect',     // 3.1 only.
			'prefixItems',           // 2020-12 tuples.
			'unevaluatedProperties', // 2020-12.
			'unevaluatedItems',      // 2020-12.
			'contentMediaType',      // 2020-12.
			'contentEncoding',       // 2020-12.
			'contentSchema',         // 2020-12.
			'dependentRequired',     // 2020-12.
			'dependentSchemas',      // 2020-12.
		);

		$offenders = array();

		$this->walk(
			$this->contract(),
			static function ( $key, $value ) use ( &$offenders, $forbidden ): void {
				if ( in_array( $key, $forbidden, true ) ) {
					$offenders[] = (string) $key;
				}

				if ( 'type' === $key ) {
					if ( is_array( $value ) ) {
						$offenders[] = 'type array';
					}

					if ( 'null' === $value ) {
						$offenders[] = "type: 'null'";
					}
				}
			}
		);

		$this->assertSame( array(), $offenders );
	}

	/**
	 * Every `$ref` points at a node that exists.
	 *
	 * A typo in a `$ref` is invisible to a reader and turns into an empty
	 * schema for a generator.
	 *
	 * @return void
	 */
	public function test_every_ref_resolves(): void {
		$refs = array();

		$this->walk(
			$this->contract(),
			static function ( $key, $value ) use ( &$refs ): void {
				if ( '$ref' === $key ) {
					$refs[] = (string) $value;
				}
			}
		);

		$this->assertNotEmpty( $refs, 'The contract is expected to use $ref.' );

		$unresolved = array();

		foreach ( array_unique( $refs ) as $ref ) {
			if ( 0 !== strpos( $ref, '#/' ) ) {
				$unresolved[] = $ref . ' (only local refs are allowed)';

				continue;
			}

			$node = $this->contract();

			foreach ( explode( '/', substr( $ref, 2 ) ) as $segment ) {
				$segment = str_replace( array( '~1', '~0' ), array( '/', '~' ), $segment );

				if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
					$unresolved[] = $ref;

					continue 2;
				}

				$node = $node[ $segment ];
			}
		}

		$this->assertSame( array(), $unresolved );
	}

	/**
	 * Each connector path documents exactly the HTTP methods the plugin
	 * registers for that route.
	 *
	 * @return void
	 */
	public function test_connector_path_methods_match_the_registered_methods(): void {
		$registered = array();

		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args[0]['methods'];

				return true;
			}
		);

		( new Rest_Controller() )->register_routes();

		$http_methods = array( 'get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace' );

		foreach ( $this->paths_hosted_by( '{rest_base}' ) as $path ) {
			$documented = array_values(
				array_intersect( array_keys( $this->contract()['paths'][ $path ] ), $http_methods )
			);

			$this->assertSame(
				strtoupper( implode( ', ', $documented ) ),
				$registered[ $path ],
				$path . ' must document exactly the methods the plugin registers.'
			);
		}
	}

	/**
	 * The plugin header states the client-site floor and the update host.
	 *
	 * @return void
	 */
	public function test_plugin_header(): void {
		$headers = $this->plugin_headers();

		$this->assertSame( WOPTIMIZE_CONNECTOR_VERSION, $headers['Version'] );
		$this->assertSame( '8.1', $headers['Requires PHP'] );
		$this->assertSame( '6.7', $headers['Requires at least'] );
		$this->assertSame( 'woptimize-connector', $headers['Text Domain'] );
		$this->assertSame(
			(string) wp_parse_url( WOPTIMIZE_PORTAL_URL, PHP_URL_HOST ),
			(string) wp_parse_url( $headers['Update URI'], PHP_URL_HOST ),
			'WordPress derives the update filter from the Update URI host (AD-17).'
		);
	}

	/**
	 * `uninstall.php` runs without the plugin loaded, so its literals have to
	 * stay in step with the class constants.
	 *
	 * @return void
	 */
	public function test_uninstall_clears_both_options_and_both_hooks(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		$this->assertStringContainsString( "delete_option( '" . Site_Key::OPTION . "' )", $source );
		$this->assertStringContainsString( "delete_option( '" . Phone_Home::OPTION . "' )", $source );
		$this->assertStringContainsString( "wp_unschedule_hook( '" . Phone_Home::HOOK . "' )", $source );
		$this->assertStringContainsString( "wp_unschedule_hook( '" . Phone_Home::RETRY_HOOK . "' )", $source );
	}

	/**
	 * The release zip carries the plugin and nothing else (AD-17).
	 *
	 * @return void
	 */
	public function test_distignore_drops_the_tooling(): void {
		$lines = array_filter(
			array_map( 'trim', explode( "\n", (string) file_get_contents( dirname( __DIR__ ) . '/.distignore' ) ) ),
			static function ( $line ) {
				return '' !== $line && 0 !== strpos( $line, '#' );
			}
		);

		foreach ( array( '/openapi.yaml', '/tests', '/vendor', '/composer.json', '/composer.lock', '/.ddev', '/phpcs.xml.dist', '/phpunit.xml.dist' ) as $expected ) {
			$this->assertContains( $expected, $lines );
		}

		$this->assertNotContains( '/includes', $lines, 'The plugin code must ship.' );
		$this->assertNotContains( '/uninstall.php', $lines, 'Uninstall cleanup must ship.' );
	}

	/**
	 * Parses the contract file once.
	 *
	 * @return array<string, mixed> The parsed document.
	 */
	private function contract(): array {
		if ( null === self::$contract ) {
			self::$contract = (array) Yaml::parseFile( dirname( __DIR__ ) . '/openapi.yaml' );
		}

		return self::$contract;
	}

	/**
	 * Lists the paths whose server URL carries a given variable.
	 *
	 * @param string $variable The server variable placeholder, e.g. `{portal}`.
	 * @return array<int, string> The matching path keys.
	 */
	private function paths_hosted_by( string $variable ): array {
		$matches = array();

		foreach ( $this->contract()['paths'] as $path => $definition ) {
			if ( false !== strpos( (string) $definition['servers'][0]['url'], $variable ) ) {
				$matches[] = (string) $path;
			}
		}

		return $matches;
	}

	/**
	 * Collects every response header name declared anywhere in the document.
	 *
	 * @param array<string, mixed> $node The document or a subtree of it.
	 * @return array<int, string> The header names found.
	 */
	private function collect_response_header_names( array $node ): array {
		$names = array();

		$this->walk(
			$node,
			static function ( $key, $value ) use ( &$names ): void {
				if ( 'headers' === $key && is_array( $value ) ) {
					foreach ( array_keys( $value ) as $name ) {
						$names[] = (string) $name;
					}
				}
			}
		);

		return $names;
	}

	/**
	 * Visits every key/value pair in a nested array.
	 *
	 * @param array<mixed, mixed> $node    The subtree to walk.
	 * @param callable            $visitor Called with ( key, value ).
	 * @return void
	 */
	private function walk( array $node, callable $visitor ): void {
		foreach ( $node as $key => $value ) {
			$visitor( $key, $value );

			if ( is_array( $value ) ) {
				$this->walk( $value, $visitor );
			}
		}
	}

	/**
	 * Reads the plugin file header.
	 *
	 * @return array<string, string> Header name to value.
	 */
	private function plugin_headers(): array {
		$source  = (string) file_get_contents( WOPTIMIZE_CONNECTOR_PLUGIN_FILE_PATH );
		$headers = array();

		if ( 1 === preg_match( '#/\*\*(.+?)\*/#s', $source, $block ) ) {
			preg_match_all( '/^\s*\*\s*([A-Za-z][A-Za-z ]*?):\s*(.+?)\s*$/m', $block[1], $found, PREG_SET_ORDER );

			foreach ( $found as $line ) {
				$headers[ $line[1] ] = $line[2];
			}
		}

		return $headers;
	}
}
