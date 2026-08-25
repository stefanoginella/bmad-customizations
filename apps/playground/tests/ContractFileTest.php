<?php
/**
 * The contract file is the suite's premise. A run that cannot read it proves
 * nothing, so it fails — it never skips.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WOptimize\Playground\Tests\Support\Contract;

/**
 * Covers the matrix row "Contract unreadable".
 */
final class ContractFileTest extends TestCase
{
    /** A path that exists nowhere, in the container or on the host. */
    private const MISSING_PATH = '/nowhere.yaml';

    /**
     * Matrix row "Contract unreadable" — expected behaviour.
     *
     * The message names the path, so a broken bind mount is readable from the
     * failure line alone.
     */
    public function test_an_unreadable_contract_file_throws_and_names_the_path(): void
    {
        try {
            Contract::load(self::MISSING_PATH);
        } catch (RuntimeException $error) {
            self::assertStringContainsString(self::MISSING_PATH, $error->getMessage());

            return;
        }

        self::fail('Contract::load() must throw a RuntimeException for an unreadable file.');
    }

    /**
     * Matrix row "Contract unreadable" — error handling: the real file loads,
     * and it is the contract this suite thinks it is validating against.
     */
    public function test_the_configured_contract_file_loads_and_lists_the_three_paths(): void
    {
        $contract = Contract::load();

        self::assertArrayHasKey('paths', $contract);

        // The matrix says the file *lists* the three paths. Their order in the
        // document is the author's business, not the suite's.
        self::assertEqualsCanonicalizing(
            ['/ping', '/status', '/phone-home'],
            array_keys($contract['paths'])
        );
    }
}
