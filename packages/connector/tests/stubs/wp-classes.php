<?php
/**
 * Minimal doubles for the WordPress core classes the connector touches.
 *
 * Brain Monkey stubs functions, not classes, so these five have to exist for
 * the unit suite to load. They copy only the behaviour the connector relies
 * on — real-WordPress behaviour is story 6's integration suite, on the
 * playground.
 *
 * Excluded from PHPCS on purpose: this mirrors core's API, not our style.
 *
 * @package woptimize-connector
 */

// phpcs:ignoreFile

class WP_Error {

	protected $errors = array();

	protected $error_data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' === $code ) {
			return;
		}

		$this->errors[ $code ][] = $message;

		if ( '' !== $data && array() !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}

	public function get_error_codes() {
		return array_keys( $this->errors );
	}

	public function get_error_code() {
		$codes = $this->get_error_codes();

		return $codes ? $codes[0] : '';
	}

	public function get_error_message( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}

		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}

	public function get_error_data( $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}

		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
	}
}

class WP_REST_Request {

	protected $method;

	protected $route;

	protected $headers = array();

	public function __construct( $method = 'GET', $route = '' ) {
		$this->method = $method;
		$this->route  = $route;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}

	public function set_route( $route ) {
		$this->route = $route;
	}

	public function set_header( $key, $value ) {
		$this->headers[ self::canonicalize_header_name( $key ) ] = $value;
	}

	public function get_header( $key ) {
		$key = self::canonicalize_header_name( $key );

		return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
	}

	public static function canonicalize_header_name( $key ) {
		return str_replace( '-', '_', strtolower( $key ) );
	}
}

class WP_REST_Response {

	protected $data;

	protected $status;

	protected $headers = array();

	public function __construct( $data = null, $status = 200, $headers = array() ) {
		$this->data    = $data;
		$this->status  = $status;
		$this->headers = $headers;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}

	public function set_status( $status ) {
		$this->status = $status;
	}

	public function header( $key, $value, $replace = true ) {
		if ( $replace || ! isset( $this->headers[ $key ] ) ) {
			$this->headers[ $key ] = $value;

			return;
		}

		$this->headers[ $key ] .= ', ' . $value;
	}

	public function get_headers() {
		return $this->headers;
	}
}

abstract class WP_REST_Controller {

	protected $namespace = '';

	protected $rest_base = '';
}

class WP_REST_Server {

	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
	const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
