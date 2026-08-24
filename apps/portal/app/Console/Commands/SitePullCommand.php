<?php

namespace App\Console\Commands;

use App\Connector\ConnectorClient;
use App\Connector\ConnectorResult;
use App\Console\Commands\Concerns\ResolvesSite;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * A human-run call out to one client site.
 *
 * A pull never writes the registry (AD-19): `last_seen_at` means "phoned home",
 * and a manual pull would fake that. Every failure — no `rest_base` yet, a 4xx,
 * a 5xx, a transport error — is one line on stderr and exit 1.
 */
abstract class SitePullCommand extends Command
{
    use ResolvesSite;

    /**
     * Execute the console command.
     */
    public function handle(ConnectorClient $client): int
    {
        $site = $this->resolveSite();

        if ($site === null) {
            return self::FAILURE;
        }

        $result = $this->pull($client, $site);

        if (! $result->ok) {
            $this->error($result->message());

            return self::FAILURE;
        }

        $this->render($site, $result);

        return self::SUCCESS;
    }

    /**
     * Makes the call this command is about.
     */
    abstract protected function pull(ConnectorClient $client, Site $site): ConnectorResult;

    /**
     * Prints what came back.
     */
    abstract protected function render(Site $site, ConnectorResult $result): void;
}
