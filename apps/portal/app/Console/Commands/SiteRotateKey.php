<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AnnouncesSiteKey;
use App\Console\Commands\Concerns\ResolvesSite;
use Illuminate\Console\Command;

/**
 * The old key is dead the same second (AD-5).
 */
class SiteRotateKey extends Command
{
    use AnnouncesSiteKey, ResolvesSite;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'site:rotate-key {site}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Issue a new site key and kill the old one';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $site = $this->resolveSite();

        if ($site === null) {
            return self::FAILURE;
        }

        $key = $site->issueKey();

        $this->info("Rotated the key of {$site->site_url}. The old key is dead.");
        $this->announceKey($key);

        return self::SUCCESS;
    }
}
