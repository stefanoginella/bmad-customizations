<?php

use App\Models\Site;
use Illuminate\Testing\TestResponse;

// `POST /api/connector/v1/phone-home` — the portal half of the daily report.
// PHONE_HOME_URL, SITE_KEY_HEADER, siteReport(), reportedSite() and
// assertRegistryUntouched() are the shared wire facts, defined in Pest.php.

/**
 * Posts a report as the given site.
 */
function phoneHome(Site $site, array $report): TestResponse
{
    return test()->postJson(PHONE_HOME_URL, $report, [SITE_KEY_HEADER => $site->site_key]);
}

/**
 * Asserts one field of an otherwise valid report is refused, and that the
 * refusal moved nothing.
 */
function rejectsReport(array $overrides, string $field): void
{
    $site = reportedSite();
    $before = $site->last_seen_at;

    phoneHome($site, siteReport($overrides))
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors($field);

    assertRegistryUntouched($site, $before);
}

// Matrix row: "Phone-home OK".
it('accepts a valid report and writes it onto the authenticated row', function () {
    $this->freezeTime();

    $site = Site::factory()->create(['site_url' => 'https://client.example']);

    $report = siteReport();

    phoneHome($site, $report)
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    $site->refresh();

    expect($site->home_url)->toBe($report['home_url'])
        ->and($site->rest_base)->toBe($report['rest_base'])
        ->and($site->connector_version)->toBe($report['connector_version'])
        ->and($site->last_report)->toBe($report)
        ->and($site->last_seen_at?->toIso8601String())->toBe(now()->toIso8601String());
});

// Matrix row: "Bad body" — `multisite: "yes"`.
it('rejects a non-boolean multisite flag and leaves last_seen_at alone', function () {
    rejectsReport(['multisite' => 'yes'], 'multisite');
});

// The contract types `multisite` as a JSON boolean, so `1` is not `true`.
it('rejects a multisite flag sent as an integer', function () {
    rejectsReport(['multisite' => 1], 'multisite');
});

// Matrix row: "Bad body" — `rest_base` missing.
it('rejects a report without a rest_base and leaves last_seen_at alone', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;

    $report = siteReport();
    unset($report['rest_base']);

    phoneHome($site, $report)
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonValidationErrors('rest_base');

    assertRegistryUntouched($site, $before);
});

// A `SiteReport` is a whole object: a nested key missing is a bad body too.
it('rejects an updates object that is missing a count', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;

    $report = siteReport();
    unset($report['updates']['wordpress']);

    phoneHome($site, $report)
        ->assertStatus(422)
        ->assertJsonValidationErrors('updates.wordpress');

    assertRegistryUntouched($site, $before);
});

it('rejects a connector version that is not semantic', function () {
    rejectsReport(['connector_version' => 'dev'], 'connector_version');
});

// The contract types the update counts as JSON integers.
it('rejects an update count sent as a string', function () {
    rejectsReport(['updates' => ['plugins' => '2']], 'updates.plugins');
});

// `url:http,https` — the bare `url` rule admits `file://` and friends, and the
// portal calls `rest_base` back.
it('rejects a rest_base on a non-http scheme', function () {
    rejectsReport(['rest_base' => 'file:///etc/passwd'], 'rest_base');
});

// The portal appends `/ping` and `/status` to `rest_base`.
it('rejects a rest_base that carries a query string', function () {
    rejectsReport(['rest_base' => 'https://client.example/wp-json/woptimize/v1?x=1'], 'rest_base');
});

// Matrix row: "Extra field" — AD-8, a newer connector may send more.
it('accepts an unknown field and never stores it', function () {
    $site = Site::factory()->create();

    $report = siteReport(['future_field' => 'from a newer connector']);

    phoneHome($site, $report)
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    $stored = $site->refresh()->last_report;

    expect($stored)->toBeArray()
        ->and($stored)->not->toHaveKey('future_field')
        ->and($stored)->toBe(siteReport());
});

// `theme.version` may be an empty string — the contract says so explicitly.
it('accepts a theme that declares no version', function () {
    $site = Site::factory()->create();

    phoneHome($site, siteReport(['theme' => ['version' => '']]))
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    expect($site->refresh()->last_report['theme']['version'])->toBe('');
});

// The contract sets no `minLength`: a WordPress site can have an empty title.
it('accepts a site that reports an empty name', function () {
    $site = Site::factory()->create();

    phoneHome($site, siteReport(['site_name' => '']))
        ->assertOk();

    expect($site->refresh()->last_report['site_name'])->toBe('');
});

// The report is the client site's fact, character for character: `TrimStrings`
// is off on the connector prefix.
it('stores a padded site name exactly as it arrived', function () {
    $site = Site::factory()->create();

    phoneHome($site, siteReport(['site_name' => ' Client Example ']))
        ->assertOk();

    expect($site->refresh()->last_report['site_name'])->toBe(' Client Example ');
});

// SSRF. A key holder writes its own report, so `rest_base` is attacker input:
// unchecked, it aims the portal's own outbound call at localhost, at the
// private network, or at a cloud metadata endpoint.
//
// The suite runs with `allow_private_rest_base` on (see Pest.php) because the
// fixtures use a name that resolves nowhere; these tests turn it off.

/**
 * A public literal IP, so the fixture's own `home_url` needs no DNS.
 */
const PUBLIC_HOST = 'https://93.184.216.34';

/**
 * Asserts a private `rest_base` is refused with the guard switched on.
 */
function rejectsPrivateRestBase(string $restBase): void
{
    config()->set('connector.allow_private_rest_base', false);

    $site = reportedSite();
    $before = $site->last_seen_at;

    phoneHome($site, siteReport([
        'home_url' => PUBLIC_HOST,
        'rest_base' => $restBase,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('rest_base');

    assertRegistryUntouched($site, $before);
}

it('refuses a rest_base on loopback', function () {
    rejectsPrivateRestBase('http://127.0.0.1/wp-json/woptimize/v1');
});

it('refuses a rest_base on the private network', function () {
    rejectsPrivateRestBase('http://10.0.0.5/wp-json/woptimize/v1');
});

it('refuses a rest_base on the cloud metadata endpoint', function () {
    rejectsPrivateRestBase('http://169.254.169.254/wp-json/woptimize/v1');
});

it('refuses a rest_base on IPv6 loopback', function () {
    rejectsPrivateRestBase('http://[::1]/wp-json/woptimize/v1');
});

it('refuses a rest_base on an IPv6 unique local address', function () {
    rejectsPrivateRestBase('http://[fd00::1]/wp-json/woptimize/v1');
});

it('refuses a rest_base on the unspecified address', function () {
    rejectsPrivateRestBase('http://0.0.0.0/wp-json/woptimize/v1');
});

// A host nobody can resolve could become anything later.
it('refuses a rest_base whose host resolves nowhere', function () {
    rejectsPrivateRestBase('http://nothing.invalid/wp-json/woptimize/v1');
});

// Local development: `*.ddev.site` resolves to 127.0.0.1.
it('accepts a private rest_base when the switch is on', function () {
    config()->set('connector.allow_private_rest_base', true);

    $site = reportedSite();

    phoneHome($site, siteReport(['rest_base' => 'http://127.0.0.1/wp-json/woptimize/v1']))
        ->assertOk()
        ->assertExactJson(['ok' => true]);

    expect($site->refresh()->rest_base)->toBe('http://127.0.0.1/wp-json/woptimize/v1');
});
