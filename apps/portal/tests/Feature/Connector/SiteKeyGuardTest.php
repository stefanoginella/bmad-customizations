<?php

use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// Matrix row: "Bad key". Every rejected key gets Laravel's default 401, is
// side-effect free, and is logged at most once per client IP per minute (AD-6).
//
// `reportedSite()` and `assertRegistryUntouched()` live in Pest.php.

/**
 * Asserts the request was refused and the registry was not touched.
 */
function assertRefusedWithoutSideEffects(TestResponse $response, Site $site, $before): void
{
    $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

    expect(Site::count())->toBe(1);

    assertRegistryUntouched($site, $before);
}

/**
 * Posts an otherwise valid report with the given key, or with none at all.
 */
function phoneHomeWithKey(?string $key): TestResponse
{
    return test()->postJson(
        PHONE_HOME_URL,
        siteReport(),
        $key === null ? [] : [SITE_KEY_HEADER => $key],
    );
}

it('refuses a request with no site-key header', function () {
    $site = reportedSite();

    assertRefusedWithoutSideEffects(phoneHomeWithKey(null), $site, $site->last_seen_at);
});

it('refuses a malformed key that is one character short', function () {
    $site = reportedSite();

    assertRefusedWithoutSideEffects(
        phoneHomeWithKey(substr($site->site_key, 0, 39)),
        $site,
        $site->last_seen_at
    );
});

// `$` alone would also match before a trailing newline.
it('refuses a well-formed key with a trailing newline', function () {
    $site = reportedSite();

    assertRefusedWithoutSideEffects(
        phoneHomeWithKey($site->site_key."\n"),
        $site,
        $site->last_seen_at
    );
});

it('refuses a well-formed key that belongs to no site', function () {
    $site = reportedSite();

    assertRefusedWithoutSideEffects(phoneHomeWithKey(Str::random(40)), $site, $site->last_seen_at);
});

it('refuses a key that has been rotated away', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;
    $old = $site->site_key;

    $site->issueKey();

    expect($site->site_key)->not->toBe($old);

    assertRefusedWithoutSideEffects(phoneHomeWithKey($old), $site, $before);
});

it('refuses the key of an offboarded site', function () {
    $site = reportedSite();
    $key = $site->site_key;

    $site->delete();

    phoneHomeWithKey($key)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    expect(Site::count())->toBe(0);
});

it('logs one warning per client IP per minute', function () {
    reportedSite();

    Log::spy();

    foreach ([1, 2] as $ignored) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson(PHONE_HOME_URL, siteReport(), [SITE_KEY_HEADER => Str::random(40)])
            ->assertUnauthorized();
    }

    Log::shouldHaveReceived('warning')->once();
});

it('logs a separate warning for each client IP', function () {
    reportedSite();

    Log::spy();

    foreach (['203.0.113.7', '203.0.113.8'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(PHONE_HOME_URL, siteReport(), [SITE_KEY_HEADER => Str::random(40)])
            ->assertUnauthorized();
    }

    Log::shouldHaveReceived('warning')->twice();
});

// A refused key is still a credential: it must never reach the log.
it('never puts the presented key in the warning', function () {
    reportedSite();

    $presented = Str::random(40);

    Log::spy();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson(PHONE_HOME_URL, siteReport(), [SITE_KEY_HEADER => $presented])
        ->assertUnauthorized();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => ! str_contains($message, $presented)
            && ! in_array($presented, $context, true)
            && ! str_contains(json_encode($context), $presented)
    );
});
