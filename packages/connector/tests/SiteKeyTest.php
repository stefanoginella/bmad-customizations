<?php
/**
 * Site key storage, format checking, and comparison.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

use Brain\Monkey\Functions;
use WOptimize\Connector\Site_Key;

/**
 * Covers Site_Key.
 */
final class SiteKeyTest extends TestCase {

	/**
	 * A well-formed key: 40 alphanumeric characters.
	 *
	 * @var string
	 */
	private const VALID_KEY = 'aB3dE5gH7jK9mN1pQ3sT5vW7yZ9bD1fH3jL5nP7r';

	/**
	 * The stored key comes back as a string.
	 *
	 * @return void
	 */
	public function test_get_returns_the_stored_key(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( Site_Key::OPTION, '' )
			->andReturn( self::VALID_KEY );

		$this->assertSame( self::VALID_KEY, Site_Key::get() );
	}

	/**
	 * A non-string option value degrades to an empty key, never a type error.
	 *
	 * @return void
	 */
	public function test_get_returns_empty_string_for_a_corrupt_option(): void {
		Functions\when( 'get_option' )->justReturn( array( 'not', 'a', 'key' ) );

		$this->assertSame( '', Site_Key::get() );
	}

	/**
	 * Only exactly 40 letters or digits are the portal's format.
	 *
	 * @dataProvider provide_format_candidates
	 *
	 * @param mixed  $candidate The value to check.
	 * @param bool   $expected  Whether it should be accepted.
	 * @param string $why       What the case is about.
	 * @return void
	 */
	public function test_is_valid_format( $candidate, bool $expected, string $why ): void {
		$this->assertSame( $expected, Site_Key::is_valid_format( $candidate ), $why );
	}

	/**
	 * Cases for the format check.
	 *
	 * @return array<string, array{0: mixed, 1: bool, 2: string}>
	 */
	public static function provide_format_candidates(): array {
		return array(
			'40 alphanumeric characters' => array( self::VALID_KEY, true, 'The portal issues exactly this shape.' ),
			'40 digits'                  => array( str_repeat( '1', 40 ), true, 'Digits only is still alphanumeric.' ),
			'39 characters'              => array( substr( self::VALID_KEY, 0, 39 ), false, 'One short must be refused.' ),
			'41 characters'              => array( self::VALID_KEY . 'x', false, 'One long must be refused.' ),
			'symbols'                    => array( str_repeat( 'a', 39 ) . '-', false, 'Symbols are not alphanumeric.' ),
			'inner whitespace'           => array( str_repeat( 'a', 20 ) . ' ' . str_repeat( 'b', 19 ), false, 'A space is not alphanumeric.' ),
			'empty string'               => array( '', false, 'Empty clears the key, it is not a key.' ),
			'not a string'               => array( 12345, false, 'Non-strings can never match.' ),
		);
	}

	/**
	 * A matching key verifies.
	 *
	 * @return void
	 */
	public function test_verify_accepts_the_stored_key(): void {
		Functions\when( 'get_option' )->justReturn( self::VALID_KEY );

		$this->assertTrue( Site_Key::verify( self::VALID_KEY ) );
	}

	/**
	 * Wrong key, missing header, and no stored key all fail the same way.
	 *
	 * @return void
	 */
	public function test_verify_rejects_everything_else(): void {
		Functions\when( 'get_option' )->justReturn( self::VALID_KEY );

		$this->assertFalse( Site_Key::verify( strrev( self::VALID_KEY ) ), 'A different key must not verify.' );
		$this->assertFalse( Site_Key::verify( null ), 'A missing header must not verify.' );
		$this->assertFalse( Site_Key::verify( '' ), 'An empty header must not verify.' );
	}

	/**
	 * With no key stored, nothing verifies — not even an empty presented key.
	 *
	 * @return void
	 */
	public function test_verify_rejects_when_no_key_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertFalse( Site_Key::verify( self::VALID_KEY ) );
		$this->assertFalse( Site_Key::verify( '' ) );
		$this->assertFalse( Site_Key::verify( null ) );
	}

	/**
	 * A malformed stored key authenticates nobody — not even someone who
	 * presents that exact malformed value.
	 *
	 * The settings screen cannot store one, but WP-CLI, a migration, or another
	 * plugin can write the option directly. A two-character option must never
	 * become a two-character credential.
	 *
	 * @dataProvider provide_malformed_stored_keys
	 *
	 * @param mixed  $stored The value found in the option.
	 * @param string $why   What the case is about.
	 * @return void
	 */
	public function test_verify_rejects_a_malformed_stored_key( $stored, string $why ): void {
		Functions\when( 'get_option' )->justReturn( $stored );

		$this->assertFalse( Site_Key::verify( is_string( $stored ) ? $stored : null ), $why );
		$this->assertFalse( Site_Key::verify( self::VALID_KEY ), $why );
	}

	/**
	 * Option values that must never authenticate.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function provide_malformed_stored_keys(): array {
		return array(
			'too short'        => array( 'abc', 'A three-character option is not a credential.' ),
			'contains symbols' => array( str_repeat( 'a', 39 ) . '!', 'Symbols are outside the portal format.' ),
			'too long'         => array( str_repeat( 'a', 41 ), 'A 41-character option is not the portal format.' ),
			'not a string'     => array( array( 'nope' ), 'A corrupt option can never authenticate.' ),
		);
	}
}
