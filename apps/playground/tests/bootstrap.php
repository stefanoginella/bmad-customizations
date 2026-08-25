<?php
/**
 * The suite has no WordPress bootstrap on purpose.
 *
 * Every observation of the site goes over HTTP or through WP-CLI, so the tests
 * see exactly what a portal and a cron run see — never WordPress internals.
 */

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
