<?php

namespace App\Console\Commands\Concerns;

/**
 * The one place a site key reaches a human.
 *
 * A key is stored encrypted and looked up by hash (AD-5), so the console is the
 * only chance to read it. Every command that issues one prints it through here,
 * exactly once and on a line of its own, so it can be copied cleanly.
 *
 * Meant for an `Illuminate\Console\Command`.
 */
trait AnnouncesSiteKey
{
    /**
     * Prints a freshly issued key, once.
     */
    protected function announceKey(string $key): void
    {
        $this->comment('Site key — shown once, store it now:');
        $this->line($key);
    }
}
