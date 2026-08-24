<?php

use App\Connector\ConnectorClient;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// The outbound half of the matrix rows "Ping / Status" and "Ping before first
// report": the portal calls the `rest_base` the site itself reported (AD-6),
// a transport error is a result rather than an exception (AD-5), and a pull
// never writes the registry (AD-19).
//
// `reportedSite()` and `assertRegistryUntouched()` live in Pest.php.

it('pings the reported rest base with the site key and the portal user agent', function () {
    $site = reportedSite();
    $key = $site->site_key;

    Http::fake([
        'client.example/wp-json/woptimize/v1/ping' => Http::response(
            ['ok' => true],
            200,
            [VERSION_HEADER => '0.1.0'],
        ),
    ]);

    $result = app(ConnectorClient::class)->ping($site);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(200)
        ->and($result->connectorVersion)->toBe('0.1.0')
        ->and($result->error)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://client.example/wp-json/woptimize/v1/ping'
        && $request->header(SITE_KEY_HEADER) === [$key]
        && $request->header('Accept') === ['application/json']
        && $request->header('User-Agent') === ['WOptimize-Portal']);
});

it('reads the report from the reported rest base', function () {
    $site = reportedSite();

    Http::fake([
        'client.example/wp-json/woptimize/v1/status' => Http::response(
            siteReport(),
            200,
            [VERSION_HEADER => '0.1.0'],
        ),
    ]);

    $result = app(ConnectorClient::class)->status($site);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(200)
        ->and($result->body)->toBe(siteReport());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://client.example/wp-json/woptimize/v1/status');
});

it('turns a connector 401 into a failed result', function () {
    $site = reportedSite();

    Http::fake(['*' => Http::response([
        'code' => 'woptimize_unauthorized',
        'message' => 'A valid WOptimize site key is required.',
        'data' => ['status' => 401],
    ], 401)]);

    $result = app(ConnectorClient::class)->ping($site);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(401);
});

it('turns a connector 5xx into a failed result', function () {
    $site = reportedSite();

    Http::fake(['*' => Http::response('', 503)]);

    $result = app(ConnectorClient::class)->status($site);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(503);
});

it('turns a transport error into a failed result instead of an exception', function () {
    $site = reportedSite();

    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

    $result = app(ConnectorClient::class)->ping($site);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBeNull()
        ->and($result->error)->toContain('Could not resolve host');
});

// A 2xx is not proof a connector answered: a login wall, a parked domain, or a
// caching layer will all answer 200 with something else.
it('refuses a 2xx that carries no JSON body', function () {
    $site = reportedSite();

    Http::fake(['*' => Http::response('<html>hello</html>', 200, [VERSION_HEADER => '0.1.0'])]);

    $result = app(ConnectorClient::class)->status($site);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(200)
        ->and($result->error)->toBe('The connector answered without a JSON body.');
});

it('refuses a 2xx that is not a 200', function () {
    $site = reportedSite();

    Http::fake(['*' => Http::response('', 204, [VERSION_HEADER => '0.1.0'])]);

    $result = app(ConnectorClient::class)->status($site);

    expect($result->ok)->toBeFalse()->and($result->status)->toBe(204);
});

// `/ping` carries no version in its body, so the contract makes the header
// required — a ping without it did not reach a connector.
it('refuses a ping that carries no version header', function () {
    $site = reportedSite();

    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $result = app(ConnectorClient::class)->ping($site);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(200)
        ->and($result->error)->toBe('The connector answered without a version header.');
});

// Matrix row: "Ping before first report".
it('refuses to call a site that has not phoned home yet', function () {
    $site = Site::factory()->create(['rest_base' => null]);

    Http::fake();

    $result = app(ConnectorClient::class)->ping($site);

    expect($result->ok)->toBeFalse()->and($result->error)->not->toBeNull();

    Http::assertNothingSent();
});

// DNS rebinding: the host passed at phone-home, but resolves privately now.
it('refuses to call a rest_base that no longer resolves to a public host', function () {
    config()->set('connector.allow_private_rest_base', false);

    $site = reportedSite();
    $site->forceFill(['rest_base' => 'http://127.0.0.1/wp-json/woptimize/v1'])->save();

    Http::fake();

    $result = app(ConnectorClient::class)->status($site);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBeNull()
        ->and($result->error)->toContain('no longer points at a public host');

    Http::assertNothingSent();
});

it('never writes the registry on a pull', function () {
    $site = reportedSite();
    $before = $site->last_seen_at;

    Http::fake(['*' => Http::response(siteReport(), 200, [VERSION_HEADER => '0.1.0'])]);

    app(ConnectorClient::class)->status($site);

    assertRegistryUntouched($site, $before);
});
