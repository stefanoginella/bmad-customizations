<?php

namespace App\Console\Commands;

use App\Connector\ConnectorClient;
use App\Connector\ConnectorResult;
use App\Models\Site;

/**
 * A pull never writes the registry (AD-19).
 */
class SiteStatus extends SitePullCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'site:status {site}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read the site report from a client site';

    /**
     * Makes the call this command is about.
     */
    protected function pull(ConnectorClient $client, Site $site): ConnectorResult
    {
        return $client->status($site);
    }

    /**
     * Prints what came back: the live `SiteReport`, as it arrived.
     */
    protected function render(Site $site, ConnectorResult $result): void
    {
        $this->line((string) json_encode(
            $result->body,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
