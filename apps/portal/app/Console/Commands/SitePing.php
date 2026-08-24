<?php

namespace App\Console\Commands;

use App\Connector\ConnectorClient;
use App\Connector\ConnectorResult;
use App\Models\Site;

/**
 * A pull never writes the registry (AD-19).
 */
class SitePing extends SitePullCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'site:ping {site}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ping a client site on its reported rest_base';

    /**
     * Makes the call this command is about.
     */
    protected function pull(ConnectorClient $client, Site $site): ConnectorResult
    {
        return $client->ping($site);
    }

    /**
     * Prints what came back.
     *
     * A ping carries no version in its body — the response header is the
     * channel (AD-5), and `ConnectorClient::ping()` has already refused a
     * response that arrived without it.
     */
    protected function render(Site $site, ConnectorResult $result): void
    {
        $this->info("ok — {$site->site_url} runs connector {$result->connectorVersion}");
    }
}
