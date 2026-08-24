<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSite;
use Illuminate\Console\Command;

/**
 * The row goes; its key answers 401 from then on.
 */
class SiteOffboard extends Command
{
    use ResolvesSite;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'site:offboard {site}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a client site from the registry';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $site = $this->resolveSite();

        if ($site === null) {
            return self::FAILURE;
        }

        $siteUrl = $site->site_url;
        $site->delete();

        $this->info("Offboarded {$siteUrl}. Its key answers 401 from now on.");

        return self::SUCCESS;
    }
}
