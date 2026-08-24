<?php

namespace App\Http\Controllers\Connector;

use App\Http\Requests\Connector\PhoneHomeRequest;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

/**
 * The portal half of the daily report (AD-6).
 *
 * `auth:connector` has already resolved the row, so this never creates one and
 * never looks a site up by anything in the body.
 */
final class PhoneHomeController
{
    /**
     * Writes the report onto the authenticated row.
     */
    public function __invoke(PhoneHomeRequest $request): JsonResponse
    {
        $report = $request->validated();

        /** @var Site $site */
        $site = $request->user();

        $site->forceFill([
            'home_url' => $report['home_url'],
            'rest_base' => $report['rest_base'],
            'connector_version' => $report['connector_version'],
            'last_seen_at' => now(),
            'last_report' => $report,
        ])->save();

        return response()->json(['ok' => true]);
    }
}
