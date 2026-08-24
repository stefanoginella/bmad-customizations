<?php

use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// The Artisan onboarding surface (AD-16): `site:onboard`, `site:list`,
// `site:rotate-key`, `site:offboard`, `site:ping`, `site:status`.
//
// `reportedSite()` and `assertRegistryUntouched()` live in Pest.php.

/**
 * Runs a command and hands back its exit code and its whole output.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, $arguments);

    return [$exit, Artisan::output()];
}

// Matrix row: "Onboard".
it('onboards a site, leaves rest_base null, and prints the key once', function () {
    [$exit, $output] = runCommand('site:onboard', ['site_url' => 'https://client.example']);

    expect($exit)->toBe(0);

    $site = Site::sole();

    expect($site->site_url)->toBe('https://client.example')
        ->and($site->rest_base)->toBeNull()
        ->and($site->home_url)->toBeNull()
        ->and($site->connector_version)->toBeNull()
        ->and($site->last_seen_at)->toBeNull()
        ->and($site->site_key)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and(substr_count($output, $site->site_key))->toBe(1);
});

// AD-5: no plaintext key column, and no lookup by decrypting rows.
it('stores the key encrypted and indexed by its hash', function () {
    runCommand('site:onboard', ['site_url' => 'https://client.example']);

    $site = Site::sole();
    $stored = DB::table('sites')->where('id', $site->id)->first();

    expect($stored->site_key)->not->toBe($site->site_key)
        ->and($stored->site_key)->not->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($stored->site_key_hash)->toBe(hash('sha256', $site->site_key));
});

// Matrix row: "Onboard", error handling — not an http(s) URL.
it('refuses to onboard something that is not an http(s) url', function () {
    [$exit] = runCommand('site:onboard', ['site_url' => 'ftp://client.example']);

    expect($exit)->toBe(1)->and(Site::count())->toBe(0);
});

// Matrix row: "Onboard", error handling — duplicate `site_url`.
it('refuses to onboard a site_url that is already registered', function () {
    Site::factory()->create(['site_url' => 'https://client.example']);

    [$exit] = runCommand('site:onboard', ['site_url' => 'https://client.example']);

    expect($exit)->toBe(1)->and(Site::count())->toBe(1);
});

// Matrix row: "Rotate".
it('rotates a key: the new one is printed and works, the old one is dead', function () {
    $site = reportedSite();
    $old = $site->site_key;

    [$exit, $output] = runCommand('site:rotate-key', ['site' => (string) $site->id]);

    $new = $site->fresh()->site_key;

    expect($exit)->toBe(0)
        ->and($new)->not->toBe($old)
        ->and($new)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and(substr_count($output, $new))->toBe(1);

    $this->postJson(PHONE_HOME_URL, siteReport(), [SITE_KEY_HEADER => $old])
        ->assertUnauthorized();

    $this->postJson(PHONE_HOME_URL, siteReport(), [SITE_KEY_HEADER => $new])
        ->assertOk();
});

// Matrix row: "Rotate", error handling.
it('fails to rotate the key of an unknown site', function () {
    expect(Artisan::call('site:rotate-key', ['site' => '404']))->toBe(1);
});

// Matrix row: "Offboard".
it('offboards a site and kills its key', function () {
    $site = reportedSite();
    $key = $site->site_key;

    [$exit] = runCommand('site:offboard', ['site' => $site->site_url]);

    expect($exit)->toBe(0)->and(Site::count())->toBe(0);

    $this->postJson(PHONE_HOME_URL, siteReport(), [SITE_KEY_HEADER => $key])
        ->assertUnauthorized();
});

// Matrix row: "Offboard", error handling.
it('fails to offboard an unknown site', function () {
    expect(Artisan::call('site:offboard', ['site' => 'https://nobody.example']))->toBe(1);
});

// A zero-padded number is not an id — resolving it as one would delete a row
// the human never named.
it('never reads a zero-padded number as an id', function () {
    $site = reportedSite();

    [$exit] = runCommand('site:offboard', ['site' => '0'.$site->id]);

    expect($exit)->toBe(1)->and(Site::count())->toBe(1);
});

// Matrix row: "Ping / Status".
it('pings a site and prints ok with the connector version', function () {
    $site = reportedSite();

    Http::fake(['*' => Http::response(['ok' => true], 200, [VERSION_HEADER => '0.1.0'])]);

    [$exit, $output] = runCommand('site:ping', ['site' => (string) $site->id]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('ok')
        ->and($output)->toContain('0.1.0');
});

// Matrix row: "Ping / Status".
it('reads a site status and prints the report', function () {
    $site = reportedSite();

    Http::fake(['*' => Http::response(siteReport(), 200, [VERSION_HEADER => '0.1.0'])]);

    [$exit, $output] = runCommand('site:status', ['site' => (string) $site->id]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('connector_version')
        ->and($output)->toContain('Client Example');
});

// Matrix row: "Ping / Status", error handling — the connector refuses the key.
it('fails the ping when the connector answers 401 and leaves the registry alone', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;

    Http::fake(['*' => Http::response(['message' => 'nope'], 401)]);

    [$exit, $output] = runCommand('site:ping', ['site' => (string) $site->id]);

    expect($exit)->toBe(1)->and($output)->toContain('401');

    assertRegistryUntouched($site, $before);
});

// Matrix row: "Ping / Status", error handling — the site is broken.
it('fails the status when the connector answers 5xx and leaves the registry alone', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;

    Http::fake(['*' => Http::response('', 500)]);

    [$exit, $output] = runCommand('site:status', ['site' => (string) $site->id]);

    expect($exit)->toBe(1)->and($output)->toContain('500');

    assertRegistryUntouched($site, $before);
});

// A 200 from something that is not a connector must not read as "alive".
it('fails the ping when the answer carries no version header', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;

    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    [$exit, $output] = runCommand('site:ping', ['site' => (string) $site->id]);

    expect($exit)->toBe(1)->and($output)->toContain('version header');

    assertRegistryUntouched($site, $before);
});

it('fails the status when the connector answers 204', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;

    Http::fake(['*' => Http::response('', 204, [VERSION_HEADER => '0.1.0'])]);

    [$exit, $output] = runCommand('site:status', ['site' => (string) $site->id]);

    expect($exit)->toBe(1)->and($output)->toContain('204');

    assertRegistryUntouched($site, $before);
});

// Matrix row: "Ping / Status", error handling — the site is unreachable.
it('fails the ping on a transport error and leaves the registry alone', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;

    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

    [$exit, $output] = runCommand('site:ping', ['site' => (string) $site->id]);

    expect($exit)->toBe(1)->and($output)->toContain('Could not resolve host');

    assertRegistryUntouched($site, $before);
});

// Matrix row: "Ping before first report".
it('refuses to ping a site that has not phoned home yet', function () {
    $site = Site::factory()->create(['rest_base' => null]);

    Http::fake();

    [$exit, $output] = runCommand('site:ping', ['site' => (string) $site->id]);

    expect($exit)->toBe(1)->and($output)->toContain('has not phoned home yet');

    Http::assertNothingSent();
});

// Matrix row: "List".
it('lists the registry without ever printing a key', function () {
    $site = reportedSite();
    $key = $site->site_key;

    [$exit, $output] = runCommand('site:list');

    expect($exit)->toBe(0)
        ->and($output)->toContain('id')
        ->and($output)->toContain('site_url')
        ->and($output)->toContain('connector_version')
        ->and($output)->toContain('last_seen_at')
        ->and($output)->toContain((string) $site->id)
        ->and($output)->toContain($site->site_url)
        ->and($output)->toContain('0.1.0')
        ->and($output)->not->toContain($key);
});
