<?php

namespace App\Console\Commands;

use App\Connector\SiteKey;
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
    protected $signature = 'site:onboard {site_url}
                            {--key= : Use this key instead of a generated one (never in production)}';

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

        $supplied = $this->option('key');
        $supplied = $supplied === null ? null : (string) $supplied;

        if ($supplied !== null && ! $this->maySupplyKey($supplied)) {
            return self::FAILURE;
        }

        // The row starts bare: `rest_base` and the rest arrive with the first
        // phone-home, never derived from the site URL (AD-6).
        $site = new Site(['site_url' => $siteUrl]);

        try {
            $key = $site->issueKey($supplied);
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
     * Whether this run may store the key a human typed, saying why when not.
     *
     * `--key` exists so the playground fixture is reproducible, nothing more.
     * A real key is drawn from `random_bytes()`; one typed on a command line is
     * in a shell history, so it never reaches production. `config/app.php`
     * defaults `APP_ENV` to `production`, so an unset environment is refused too.
     */
    private function maySupplyKey(string $key): bool
    {
        if ($this->laravel->isProduction()) {
            $this->error('--key is a fixture option and never works in production.');

            return false;
        }

        if (! SiteKey::isValidFormat($key)) {
            $this->error('--key must be 40 alphanumeric characters.');

            return false;
        }

        return true;
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
