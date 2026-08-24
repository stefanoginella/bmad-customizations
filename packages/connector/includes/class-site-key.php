<?php
/**
 * Storage and comparison of the portal-issued site key.
 *
 * @package woptimize-connector
 */

namespace WOptimize\Connector;

defined( 'ABSPATH' ) || exit;

/**
 * The site key: read it, check its shape, compare it.
 *
 * The portal issues every key (AD-16). This plugin never generates, derives,
 * or changes one — the option is a cache of a portal-issued fact, and a human
 * pastes it into the settings screen.
 */
final class Site_Key {

	/**
	 * The option holding the pasted key.
	 *
	 * @var string
	 */
	const OPTION = 'woptimize_connector_site_key';

	/**
	 * The header carrying the key, in both directions (AD-5).
	 *
	 * @var string
	 */
	const HEADER = 'X-Woptimize-Site-Key';

	/**
	 * The fixed key length (AD-16).
	 *
	 * @var int
	 */
	const LENGTH = 40;

	/**
	 * Reads the stored key.
	 *
	 * @return string The key, or an empty string when none is stored.
	 */
	public static function get() {
		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Tells whether a candidate has the portal's key format.
	 *
	 * Format only. A well-formed key can still be wrong, revoked, or unknown to
	 * the portal — that answer comes back as a 401 (AD-7).
	 *
	 * @param mixed $candidate The value to check.
	 * @return bool True when the value is exactly 40 alphanumeric characters.
	 */
	public static function is_valid_format( $candidate ) {
		if ( ! is_string( $candidate ) ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9]{' . self::LENGTH . '}$/', $candidate );
	}

	/**
	 * Compares a presented key against the stored one in constant time.
	 *
	 * The stored value has to pass the format check as well. Options can be
	 * written from WP-CLI, a migration, or another plugin — never from the
	 * settings screen alone — and a malformed value there must authenticate
	 * nobody, not even someone who guessed that malformed value.
	 *
	 * @param string|null $candidate The presented key, or null when the header was absent.
	 * @return bool True only when a well-formed key is stored and the two match exactly.
	 */
	public static function verify( ?string $candidate ) {
		$stored = self::get();

		if ( ! self::is_valid_format( $stored ) || null === $candidate || '' === $candidate ) {
			return false;
		}

		return hash_equals( $stored, $candidate );
	}
}
