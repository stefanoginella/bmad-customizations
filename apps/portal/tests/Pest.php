<?php

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // The fixtures report `https://client.example`, a reserved name that
    // resolves nowhere, so `App\Connector\Rules\PublicHost` would refuse every
    // one of them. The tests that are about that rule turn this back off.
    ->beforeEach(fn () => config()->set('connector.allow_private_rest_base', true))
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The portal-hosted contract path, spelled out on the wire.
 *
 * `packages/connector/openapi.yaml` is the source of truth for it, and
 * `tests/Feature/ContractTest.php` is what ties the file to
 * `App\Connector\Contract`.
 */
const PHONE_HOME_URL = '/api/connector/v1/phone-home';

/**
 * The header that carries the portal-issued site key.
 */
const SITE_KEY_HEADER = 'X-Woptimize-Site-Key';

/**
 * The header the connector reports its version in.
 */
const VERSION_HEADER = 'X-Woptimize-Connector-Version';

/**
 * One onboarded site that has already phoned home, on a fixed host so an
 * `Http::fake()` URL pattern can match it.
 *
 * Read its plaintext key with `$site->site_key`: the `encrypted` cast decrypts
 * on the way out, which is also how the guard reads it.
 */
function reportedSite(): Site
{
    return Site::factory()->phonedHome()->create([
        'site_url' => 'https://client.example',
    ]);
}

/**
 * Asserts a pull, or a refused request, left the registry alone.
 *
 * `last_seen_at` means "phoned home" (AD-19), so anything else moving it is a
 * bug — a manual pull would fake the one signal the portal has that a client's
 * cron still works.
 */
function assertRegistryUntouched(Site $site, ?DateTimeInterface $before): void
{
    expect($site->fresh()?->last_seen_at?->toIso8601String())
        ->toBe($before?->toIso8601String());
}

/**
 * A valid `SiteReport` body, exactly as `packages/connector/openapi.yaml`
 * types it and as `Site_Report::build()` on the connector emits it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function siteReport(array $overrides = []): array
{
    return array_replace_recursive([
        'connector_version' => '0.1.0',
        'site_url' => 'https://client.example',
        'home_url' => 'https://client.example',
        'rest_base' => 'https://client.example/wp-json/woptimize/v1',
        'site_name' => 'Client Example',
        'wp_version' => '6.7.1',
        'php_version' => '8.3.14',
        'locale' => 'en_US',
        'timezone' => 'Europe/Madrid',
        'multisite' => false,
        'theme' => [
            'slug' => 'twentytwentyfive',
            'name' => 'Twenty Twenty-Five',
            'version' => '1.1',
        ],
        'updates' => [
            'wordpress' => 0,
            'plugins' => 2,
            'themes' => 1,
        ],
    ], $overrides);
}
