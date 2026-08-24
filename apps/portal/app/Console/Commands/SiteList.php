<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Prints the registry. Never prints a key.
 */
class SiteList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'site:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List the registered client sites';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rows = Site::query()
            ->orderBy('id')
            ->get(['id', 'site_url', 'connector_version', 'last_seen_at'])
            ->map(fn (Site $site): array => [
                $site->id,
                $site->site_url,
                $site->connector_version ?? '-',
                $site->last_seen_at?->toDateTimeString() ?? 'never',
            ])
            ->all();

        $this->table(['id', 'site_url', 'connector_version', 'last_seen_at'], $rows);

        return self::SUCCESS;
    }
}
