<?php

namespace App\Connector;

use App\Connector\Rules\PublicHost;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls a client site on the `rest_base` that site reported (AD-6).
 *
 * A pull never writes the registry — `last_seen_at` means "phoned home".
 *
 * "Reachable" is not "answered the contract": only a `200` carrying a JSON
 * object counts as ok. A WordPress site behind a login wall, a parked domain,
 * or a caching layer will happily answer 2xx with something else.
 */
final class ConnectorClient
{
    /**
     * Liveness check against `{rest_base}/ping`.
     *
     * `/ping` carries no version in its body, so the contract makes the
     * version header required — a ping without it did not reach a connector.
     */
    public function ping(Site $site): ConnectorResult
    {
        $result = $this->get($site, 'ping');

        if ($result->ok && $result->connectorVersion === null) {
            return new ConnectorResult(
                ok: false,
                status: $result->status,
                body: $result->body,
                error: 'The connector answered without a version header.',
            );
        }

        return $result;
    }

    /**
     * Reads the site report from `{rest_base}/status`.
     */
    public function status(Site $site): ConnectorResult
    {
        return $this->get($site, 'status');
    }

    /**
     * One GET against the reported REST base, with the site key on it.
     */
    private function get(Site $site, string $endpoint): ConnectorResult
    {
        if ($site->rest_base === null) {
            return new ConnectorResult(
                ok: false,
                error: "{$site->site_url} has not phoned home yet, so the portal has no rest_base to call.",
            );
        }

        // Phone-home checked this host, but DNS may have moved since.
        if (! config('connector.allow_private_rest_base') && ! (new PublicHost)->accepts($site->rest_base)) {
            return new ConnectorResult(
                ok: false,
                error: "{$site->site_url} reports a rest_base that no longer points at a public host.",
            );
        }

        try {
            $response = Http::withHeaders([Contract::SITE_KEY_HEADER => $site->site_key])
                ->acceptJson()
                ->withUserAgent(Contract::USER_AGENT)
                ->withoutRedirecting()
                ->timeout(Contract::TIMEOUT_SECONDS)
                ->get(rtrim($site->rest_base, '/').'/'.$endpoint);
        } catch (ConnectionException $e) {
            return new ConnectorResult(ok: false, error: $e->getMessage());
        }

        return $this->interpret($response);
    }

    /**
     * Turns one HTTP response into a result the contract would recognise.
     */
    private function interpret(Response $response): ConnectorResult
    {
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        $status = $response->status();
        $version = $response->header(Contract::VERSION_HEADER) ?: null;

        if ($status !== 200) {
            return new ConnectorResult(ok: false, status: $status, body: $body, connectorVersion: $version);
        }

        if ($body === null) {
            return new ConnectorResult(
                ok: false,
                status: $status,
                connectorVersion: $version,
                error: 'The connector answered without a JSON body.',
            );
        }

        return new ConnectorResult(ok: true, status: $status, body: $body, connectorVersion: $version);
    }
}
