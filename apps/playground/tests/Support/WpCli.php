<?php
/**
 * WP-CLI is the suite's only way into WordPress state.
 *
 * `DISABLE_WP_CRON` is on in the playground, so an event fires only when a
 * test asks for it — never in the middle of another scenario.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs `wp` in the playground container and reads back what it printed.
 */
final class WpCli
{
    /**
     * How long one `wp` call may take, in seconds.
     *
     * A phone-home runs inside the CLI process and the connector gives the
     * portal 10 seconds, so this only has to be comfortably larger.
     */
    private const TIMEOUT = 120;

    /**
     * Runs one `wp` command and hands back its standard output.
     *
     * @param  array<int, string>  $args  The command, already split into words.
     * @param  array<string, string>  $env  Extra environment for this run only —
     *                                      `WOPTIMIZE_PORTAL_URL` is how the
     *                                      unreachable-portal scenario is set up.
     * @return string The command's standard output.
     *
     * @throws RuntimeException When `wp` exits non-zero.
     */
    public static function run(array $args, array $env = []): string
    {
        $process = self::capture($args, $env);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "`wp %s` exited %d.\n%s",
                implode(' ', $args),
                (int) $process->getExitCode(),
                trim($process->getErrorOutput().$process->getOutput())
            ));
        }

        return $process->getOutput();
    }

    /**
     * Reads one option as JSON.
     *
     * @param  string  $option  The option name.
     * @return array<string, mixed> The decoded option value, empty when unset.
     */
    public static function optionJson(string $option): array
    {
        // An option that was never written makes `wp option get` exit 1. That
        // is "no value recorded yet", which is a state the scenarios start from.
        $process = self::capture(['option', 'get', $option, '--format=json']);

        if (! $process->isSuccessful()) {
            return [];
        }

        $decoded = json_decode(trim($process->getOutput()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Lists the scheduled cron events, optionally for one hook only.
     *
     * Unparseable output is an error, never an empty list: "no retry event is
     * queued" is the whole point of the AD-7 assertions, and it must not be
     * satisfiable by garbage.
     *
     * @param  string|null  $hook  Keep only this hook's events; all when null.
     * @return array<int, array<string, mixed>> One entry per event, each with
     *                                          at least a `hook` key.
     *
     * @throws RuntimeException When the listing does not decode as JSON.
     */
    public static function cronEvents(?string $hook = null): array
    {
        $output = trim(self::run(['cron', 'event', 'list', '--format=json']));
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                '`wp cron event list --format=json` did not answer with JSON: '.$output
            );
        }

        if ($hook === null) {
            return $decoded;
        }

        return array_values(array_filter(
            $decoded,
            static fn (array $event): bool => ($event['hook'] ?? null) === $hook
        ));
    }

    /**
     * Runs one `wp` call and hands back the finished process, successful or not.
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $env
     */
    private static function capture(array $args, array $env = []): Process
    {
        // The suite runs from the project root, one level above the docroot, so
        // WP-CLI's own upward search never finds wp-config.php.
        $process = new Process(
            array_merge(['wp', '--path='.self::wordpressPath()], $args),
            null,
            $env,
            null,
            self::TIMEOUT
        );

        $process->run();

        return $process;
    }

    /**
     * Where WordPress is installed inside the container.
     */
    private static function wordpressPath(): string
    {
        $approot = (string) getenv('DDEV_APPROOT');
        $docroot = (string) getenv('DDEV_DOCROOT');

        if ($approot === '' || $docroot === '') {
            throw new RuntimeException(
                'DDEV_APPROOT and DDEV_DOCROOT are not set. Run the suite inside '
                .'the playground container: '
                .'`ddev exec vendor/bin/phpunit --exclude-group offboarded`.'
            );
        }

        return rtrim($approot, '/').'/'.trim($docroot, '/');
    }
}
