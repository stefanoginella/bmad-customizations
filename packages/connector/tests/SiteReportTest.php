<?php
/**
 * The SiteReport payload, in particular the update counts.
 *
 * @package woptimize-connector
 */

declare(strict_types=1);

namespace WOptimize\Connector\Tests;

use WOptimize\Connector\Site_Report;

/**
 * Covers Site_Report.
 */
final class SiteReportTest extends TestCase {

	/**
	 * Update counts come from the three site transients.
	 *
	 * `wp_get_update_data()` is capability-gated and would report zeroes to a
	 * REST request or a cron run, so the transients are read directly.
	 *
	 * @return void
	 */
	public function test_updates_are_counted_from_the_site_transients(): void {
		$this->stub_site_report(
			array(
				'update_core'    => (object) array(
					'updates' => array(
						(object) array( 'response' => 'upgrade' ),
						(object) array( 'response' => 'latest' ),
						(object) array( 'response' => 'upgrade' ),
					),
				),
				'update_plugins' => (object) array(
					'response' => array(
						'akismet/akismet.php' => (object) array(),
					),
				),
				'update_themes'  => (object) array(
					'response' => array(
						'twentytwentyfour' => array(),
						'twentytwentyfive' => array(),
					),
				),
			)
		);

		$this->assertSame(
			array(
				'wordpress' => 2,
				'plugins'   => 1,
				'themes'    => 2,
			),
			Site_Report::build()['updates']
		);
	}

	/**
	 * A site WordPress has not checked yet reports zeroes, not a fatal.
	 *
	 * @dataProvider provide_empty_transients
	 *
	 * @param mixed  $value The transient value.
	 * @param string $why   What the case is about.
	 * @return void
	 */
	public function test_updates_are_zero_without_usable_transients( $value, string $why ): void {
		$this->stub_site_report(
			array(
				'update_core'    => $value,
				'update_plugins' => $value,
				'update_themes'  => $value,
			)
		);

		$this->assertSame(
			array(
				'wordpress' => 0,
				'plugins'   => 0,
				'themes'    => 0,
			),
			Site_Report::build()['updates'],
			$why
		);
	}

	/**
	 * Transient values that carry no counts.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function provide_empty_transients(): array {
		return array(
			'never checked'   => array( false, 'An absent transient is zero updates.' ),
			'no response key' => array( (object) array(), 'A transient without the key is zero updates.' ),
			'wrong shape'     => array( (object) array( 'response' => 'nonsense' ), 'A non-array response is zero updates.' ),
		);
	}

	/**
	 * The scalar half of the report is passed through as strings.
	 *
	 * @return void
	 */
	public function test_report_carries_the_site_facts(): void {
		$this->stub_site_report( array() );

		$report = Site_Report::build();

		$this->assertSame( WOPTIMIZE_CONNECTOR_VERSION, $report['connector_version'] );
		$this->assertSame( 'https://client.example', $report['site_url'] );
		$this->assertSame( 'https://client.example', $report['home_url'] );
		$this->assertSame( 'https://client.example/wp-json/woptimize/v1', $report['rest_base'] );
		$this->assertSame( 'A Client Site', $report['site_name'] );
		$this->assertSame( '6.7.1', $report['wp_version'] );
		$this->assertSame( PHP_VERSION, $report['php_version'] );
		$this->assertSame( 'en_US', $report['locale'] );
		$this->assertSame( 'Europe/Madrid', $report['timezone'] );
		$this->assertFalse( $report['multisite'] );
		$this->assertSame(
			array(
				'slug'    => 'twentytwentyfive',
				'name'    => 'Twenty Twenty-Five',
				'version' => '1.2',
			),
			$report['theme']
		);
	}
}
