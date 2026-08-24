<?php

namespace Database\Factories;

use App\Connector\SiteKey;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * The model this factory builds.
     *
     * @var class-string<Site>
     */
    protected $model = Site::class;

    /**
     * An onboarded site that has not phoned home yet.
     *
     * The key is stored the way `App\Connector\SiteKey` stores one (AD-5):
     * encrypted in `site_key`, indexed by its sha256 in `site_key_hash`. A
     * test that needs the plaintext reads `$site->site_key` — the `encrypted`
     * cast decrypts on the way out.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = SiteKey::generate();

        return [
            'site_url' => 'https://'.fake()->unique()->domainName(),
            'home_url' => null,
            'rest_base' => null,
            'connector_version' => null,
            'last_seen_at' => null,
            'last_report' => null,
            'site_key' => $key,
            'site_key_hash' => SiteKey::hash($key),
        ];
    }

    /**
     * A site that has already reported.
     *
     * `home_url` and `rest_base` are derived after making, not in the state
     * closure: a state sees only the attributes set before it, and `create()`
     * appends its own overrides last — so a test that pins `site_url` would
     * otherwise get a `rest_base` on a different host.
     */
    public function phonedHome(): static
    {
        return $this->state(fn (): array => [
            'connector_version' => '0.1.0',
            'last_seen_at' => now()->subDay(),
            'last_report' => ['connector_version' => '0.1.0'],
        ])->afterMaking(function (Site $site): void {
            $site->home_url ??= $site->site_url;
            $site->rest_base ??= rtrim((string) $site->site_url, '/').'/wp-json/woptimize/v1';
        });
    }
}
