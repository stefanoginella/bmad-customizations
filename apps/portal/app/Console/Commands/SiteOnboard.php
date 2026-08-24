<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AnnouncesSiteKey;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates the registry row and prints the key once (AD-16).
 */
class SiteOnboard extends Command
{
    use AnnouncesSiteKey;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'site:onboard {site_url}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register a client site and issue its site key';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $siteUrl = (string) $this->argument('site_url');

        if (! $this->isHttpUrl($siteUrl)) {
            $this->error("\"{$siteUrl}\" is not an http(s) URL.");

            return self::FAILURE;
        }

        // The row starts bare: `rest_base` and the rest arrive with the first
        // phone-home, never derived from the site URL (AD-6).
        $site = new Site(['site_url' => $siteUrl]);

        try {
            $key = $site->issueKey();
        } catch (UniqueConstraintViolationException) {
            // The unique index is the arbiter, not a prior SELECT: two
            // concurrent onboards would both pass the check and one would
            // still lose here.
            $this->error("\"{$siteUrl}\" is already registered.");

            return self::FAILURE;
        }

        $this->info("Registered {$site->site_url} as site {$site->id}.");
        $this->announceKey($key);

        return self::SUCCESS;
    }

    /**
     * The site URL is human-entered, so it is checked before anything else.
     */
    private function isHttpUrl(string $candidate): bool
    {
        return filter_var($candidate, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($candidate, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
