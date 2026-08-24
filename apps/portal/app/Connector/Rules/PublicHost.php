<?php

namespace App\Connector\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a URL whose host lands anywhere but the public internet.
 *
 * A site key holder writes its own `SiteReport`, and the portal calls the
 * `rest_base` it finds there (AD-6). Without this, a key holder can aim that
 * call at `http://127.0.0.1:9200/`, at a neighbour on the private network, or
 * at `http://169.254.169.254/` — server-side request forgery from inside the
 * portal's own network. `withoutRedirecting()` on the client closes the other
 * half: a public host cannot bounce the call somewhere private.
 *
 * The check is on the resolved addresses, not the name, so
 * `evil.example -> 127.0.0.1` is refused too. A host that resolves nowhere is
 * refused as well: the portal has no way to know where it would land later.
 */
final class PublicHost implements ValidationRule
{
    private const MESSAGE = 'The rest_base must point at a public host.';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Local development: `*.ddev.site` resolves to 127.0.0.1, so every
        // honest client site would be refused.
        if (config('connector.allow_private_rest_base')) {
            return;
        }

        if (! is_string($value)) {
            $fail(self::MESSAGE);

            return;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $fail(self::MESSAGE);

            return;
        }

        // `parse_url` keeps the brackets of an IPv6 literal.
        $addresses = $this->resolve(trim($host, '[]'));

        if ($addresses === []) {
            $fail(self::MESSAGE);

            return;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                $fail(self::MESSAGE);

                return;
            }
        }
    }

    /**
     * Every address a host stands for. A literal IP stands for itself.
     *
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                if (isset($record[$key])) {
                    $addresses[] = (string) $record[$key];
                }
            }
        }

        if ($addresses === []) {
            // Some resolvers answer `gethostbyname*` but not `dns_get_record`.
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether one address is routable on the public internet.
     */
    private function isPublic(string $address): bool
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            return false;
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        // PHP's flags have missed these before; state them outright.
        return match (strlen($packed)) {
            // 0.0.0.0/8 — "this network".
            4 => ord($packed[0]) !== 0,
            // fc00::/7 — unique local addresses.
            16 => (ord($packed[0]) & 0xFE) !== 0xFC,
            default => false,
        };
    }
}
