<?php

namespace App\Connector;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The `connector` guard: resolves one request's site key to its row (AD-6).
 *
 * Registered with `Auth::viaRequest('site-key', …)`, so returning null is what
 * makes the `auth` middleware answer Laravel's default
 * `401 {"message":"Unauthenticated."}`.
 */
final class SiteKeyGuard
{
    /**
     * Resolves the authenticated site, or null.
     */
    public function __invoke(Request $request): ?Site
    {
        $site = Site::findByKey($request->header(Contract::SITE_KEY_HEADER));

        if ($site === null) {
            $this->warnAboutRefusedKey($request);
        }

        return $site;
    }

    /**
     * Logs one warning per client IP per minute for a refused key (AD-6).
     *
     * A refused key is the only signal the portal has that a client site is
     * misconfigured, and a loud site must not be able to flood the log. The
     * key itself never goes near it, and nothing here may break auth: a broken
     * cache store must still produce a clean 401, not a 500.
     */
    private function warnAboutRefusedKey(Request $request): void
    {
        try {
            RateLimiter::attempt(
                'connector.refused-key:'.$request->ip(),
                maxAttempts: 1,
                callback: fn () => Log::warning('Refused a connector site key.', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]),
            );
        } catch (Throwable) {
            // Logging is best effort. The 401 is not.
        }
    }
}
