<?php

namespace App\Console\Commands\Concerns;

use App\Models\Site;

/**
 * The shared `{site}` argument: an id or a `site_url`.
 *
 * Meant for an `Illuminate\Console\Command` — it uses that class's `argument()`
 * and `error()`.
 */
trait ResolvesSite
{
    /**
     * Resolves the `{site}` argument, reporting the miss itself.
     *
     * An argument counts as an id only when it survives a round trip through
     * `int` unchanged: `ctype_digit()` would read "0123" as site 123, and a
     * typo must never resolve to somebody else's row.
     */
    protected function resolveSite(): ?Site
    {
        $identifier = (string) $this->argument('site');

        $site = (string) (int) $identifier === $identifier
            ? Site::query()->find((int) $identifier)
            : Site::query()->where('site_url', $identifier)->first();

        if ($site === null) {
            $this->error("No registered site matches \"{$identifier}\".");
        }

        return $site;
    }
}
