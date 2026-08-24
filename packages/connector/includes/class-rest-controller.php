<?php
/**
 * The connector-hosted half of the contract: the `woptimize/v1` REST routes.
 *
 * @package woptimize-connector
 */

namespace WOptimize\Connector;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and serves `GET /ping` and `GET /status`.
 *
 * Both routes are gated by the same site key (AD-5), and every response under
 * the namespace — success or error — carries the connector version header.
 */
final class Rest_Controller extends WP_REST_Controller {

	/**
	 * The connector-hosted namespace. Additive changes only inside v1 (AD-5).
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'woptimize/v1';

	/**
	 * The response header that reports the connector version.
	 *
	 * @var string
	 */
	const VERSION_HEADER = 'X-Woptimize-Connector-Version';

	/**
	 * Sets the namespace this controller serves.
	 */
	public function __construct() {
		$this->namespace = self::REST_NAMESPACE;
		$this->rest_base = '';
	}

	/**
	 * Hooks the controller into WordPress.
	 *
	 * @return void
	 */
	public static function boot() {
		$controller = new self();

		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_version_header' ), 10, 3 );
	}

	/**
	 * Registers every route in the namespace.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/ping',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'ping' ),
					'permission_callback' => array( $this, 'check_key' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'check_key' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Answers `GET /ping`.
	 *
	 * The body carries no version — the response header is the channel (AD-5).
	 *
	 * @param WP_REST_Request $request The request. Unused.
	 * @return WP_REST_Response The `Ping` payload.
	 */
	public function ping( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the REST callback signature.
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Answers `GET /status`.
	 *
	 * @param WP_REST_Request $request The request. Unused.
	 * @return WP_REST_Response The `SiteReport` payload.
	 */
	public function status( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the REST callback signature.
		return new WP_REST_Response( Site_Report::build(), 200 );
	}

	/**
	 * The permission callback shared by every route in the namespace.
	 *
	 * Missing header, empty stored key, and mismatch are one answer: a 401 in
	 * the WordPress core error envelope, with nothing logged.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True when the key matches, a 401 error otherwise.
	 */
	public function check_key( $request ) {
		$presented = $request->get_header( Site_Key::HEADER );

		if ( Site_Key::verify( is_string( $presented ) ? $presented : null ) ) {
			return true;
		}

		return new WP_Error(
			'woptimize_unauthorized',
			__( 'A valid WOptimize site key is required.', 'woptimize-connector' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Adds the connector version header to every response in the namespace.
	 *
	 * Runs after core turned a `WP_Error` into a response, so 401s carry the
	 * header too.
	 *
	 * @param mixed           $result  The response about to be served.
	 * @param WP_REST_Server  $server  The REST server. Unused.
	 * @param WP_REST_Request $request The request that produced it.
	 * @return mixed The response, unchanged for routes outside the namespace.
	 */
	public static function add_version_header( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the rest_post_dispatch filter signature.
		if ( ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$prefix = '/' . self::REST_NAMESPACE;
		$route  = (string) $request->get_route();

		if ( $route !== $prefix && 0 !== strpos( $route, $prefix . '/' ) ) {
			return $result;
		}

		$result->header( self::VERSION_HEADER, WOPTIMIZE_CONNECTOR_VERSION );

		return $result;
	}
}
