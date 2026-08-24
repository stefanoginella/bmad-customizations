<?php

namespace App\Connector;

/**
 * The outcome of one outbound call to a client site.
 *
 * A transport error is a result, not an exception (AD-5).
 */
final class ConnectorResult
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $status = null,
        public readonly ?array $body = null,
        public readonly ?string $connectorVersion = null,
        public readonly ?string $error = null,
    ) {}

    /**
     * One line a command can print when the call did not succeed.
     */
    public function message(): string
    {
        return $this->error ?? "The connector answered HTTP {$this->status}.";
    }
}
