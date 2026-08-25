<?php
/**
 * `packages/connector/openapi.yaml` is the source of truth (AD-4), and this is
 * how the suite holds every live response against it.
 */

declare(strict_types=1);

namespace WOptimize\Playground\Tests\Support;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Loads the contract file and validates responses against it.
 *
 * The file is never optional: when it is unreadable the suite fails, it never
 * skips. A skipped contract check is a green run that proves nothing.
 */
final class Contract
{
    /** Where `docker-compose.connector.yaml` mounts the contract file. */
    public const DEFAULT_PATH = '/mnt/woptimize/connector/openapi.yaml';

    /** The environment variable that overrides the path, for the previous leg. */
    public const PATH_ENV = 'WOPTIMIZE_CONTRACT_FILE';

    /**
     * The validator for the configured file, built on first use.
     *
     * Building one parses and resolves the whole document; the suite asserts
     * against it dozens of times.
     */
    private static ?ResponseValidator $validator = null;

    /**
     * The contract file this run validates against.
     */
    public static function path(): string
    {
        $configured = getenv(self::PATH_ENV);

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::DEFAULT_PATH;
    }

    /**
     * Parses the contract file and hands back the whole document.
     *
     * @param  string|null  $path  The file to read; the configured one when null.
     * @return array<string, mixed> The parsed contract document.
     *
     * @throws RuntimeException When the file cannot be read or parsed. The
     *                          message names the path, so a broken mount is
     *                          obvious from the failure line alone.
     */
    public static function load(?string $path = null): array
    {
        $path ??= self::path();

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                "The contract file {$path} is not readable. "
                .'It reaches the container as a read-only mount of packages/connector; '
                .'check .ddev/docker-compose.connector.yaml and '.self::PATH_ENV.'.'
            );
        }

        try {
            $document = Yaml::parseFile($path);
        } catch (ParseException $error) {
            throw new RuntimeException(
                "The contract file {$path} is not valid YAML: {$error->getMessage()}",
                0,
                $error
            );
        }

        if (! is_array($document)) {
            throw new RuntimeException("The contract file {$path} holds no OpenAPI document.");
        }

        return $document;
    }

    /**
     * The response validator for the configured contract file.
     *
     * `load()` owns the readable check, so a broken mount fails with the
     * message that names the path instead of with a parser's own wording.
     */
    private static function validator(): ResponseValidator
    {
        if (self::$validator === null) {
            $path = self::path();

            self::load($path);

            self::$validator = (new ValidatorBuilder())->fromYamlFile($path)->getResponseValidator();
        }

        return self::$validator;
    }

    /**
     * Asserts a live response matches the operation the contract documents.
     *
     * The response's own status code selects the documented response, so one
     * call covers the body schema and the documented headers together.
     *
     * @throws RuntimeException When the response does not match the contract.
     */
    public static function assertResponseMatches(
        OperationAddress $address,
        ResponseInterface $response
    ): void {
        try {
            self::validator()->validate($address, $response);
        } catch (ValidationFailed $failure) {
            throw new RuntimeException(sprintf(
                '%s %s answered %d with something %s does not document: %s',
                strtoupper($address->method()),
                $address->path(),
                $response->getStatusCode(),
                self::path(),
                self::explain($failure)
            ), 0, $failure);
        }
    }

    /**
     * Flattens a validation failure into one readable line.
     *
     * The library reports the interesting part — which property, which keyword
     * — in the wrapped exceptions, so a bare `getMessage()` says only
     * "Validation failed".
     */
    private static function explain(ValidationFailed $failure): string
    {
        $messages = [];

        for ($error = $failure; $error instanceof Throwable; $error = $error->getPrevious()) {
            $messages[] = $error->getMessage();
        }

        return implode(' -> ', array_unique(array_filter($messages)));
    }
}
