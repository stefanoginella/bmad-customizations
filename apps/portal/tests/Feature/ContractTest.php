<?php

use App\Connector\Contract;
use App\Http\Requests\Connector\PhoneHomeRequest;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

// `packages/connector/openapi.yaml` is the source of truth (AD-4). This is the
// portal-side guard that the file and this app still describe the same thing.
// `.ddev/docker-compose.contract.yaml` mounts the file read-only; when it is
// missing this test FAILS — it never skips.

/**
 * Parses the mounted contract file.
 *
 * @return array<string, mixed>
 */
function contract(): array
{
    static $parsed = null;

    $path = (string) env('WOPTIMIZE_CONTRACT_FILE', '/mnt/woptimize/openapi.yaml');

    if (! is_file($path) || ! is_readable($path)) {
        Assert::fail(
            "The contract file is not readable at {$path}. ".
            '`.ddev/docker-compose.contract.yaml` mounts it read-only; run `ddev restart`.'
        );
    }

    return $parsed ??= (array) Yaml::parseFile($path);
}

/**
 * Lists the paths whose own server URL carries a given host placeholder.
 *
 * Every path overrides the document's `servers` with the single host that
 * actually serves it, so this is what splits the contract in two.
 *
 * @return array<int, string>
 */
function pathsHostedBy(string $variable): array
{
    $matches = [];

    foreach (contract()['paths'] as $path => $definition) {
        if (str_contains((string) ($definition['servers'][0]['url'] ?? ''), $variable)) {
            $matches[] = (string) $path;
        }
    }

    return $matches;
}

/**
 * The HTTP methods one path definition declares.
 *
 * @param  array<mixed, mixed>  $definition
 * @return array<int, string>
 */
function methodsOf(array $definition): array
{
    return array_values(array_intersect(
        array_keys($definition),
        ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'],
    ));
}

/**
 * Every header name in the document that points at the `ConnectorVersion`
 * header component.
 *
 * @param  array<mixed, mixed>|null  $node
 * @return array<int, string>
 */
function connectorVersionHeaderNames(?array $node = null): array
{
    $names = [];

    foreach ($node ?? contract() as $key => $value) {
        if (! is_array($value)) {
            continue;
        }

        if ($key === 'headers') {
            foreach ($value as $name => $definition) {
                if (is_array($definition) && ($definition['$ref'] ?? null) === '#/components/headers/ConnectorVersion') {
                    $names[] = (string) $name;
                }
            }
        }

        $names = [...$names, ...connectorVersionHeaderNames($value)];
    }

    return $names;
}

/**
 * The dotted rule keys of the phone-home FormRequest that sit under one
 * top-level key, with that prefix stripped.
 *
 * @return array<int, string>
 */
function nestedRuleKeys(string $parent): array
{
    $keys = [];

    foreach (array_keys((new PhoneHomeRequest)->rules()) as $rule) {
        if (str_starts_with($rule, $parent.'.')) {
            $keys[] = substr($rule, strlen($parent) + 1);
        }
    }

    return array_values(array_unique($keys));
}

it('serves exactly one contract path, under the portal prefix', function () {
    expect(pathsHostedBy('{portal}'))->toBe(['/phone-home']);

    $definition = contract()['paths']['/phone-home'];

    expect(methodsOf($definition))->toBe(['post'])
        ->and($definition['servers'][0]['url'])->toBe('{portal}'.Contract::PATH_PREFIX);
});

// The other half of the contract is the connector's, and the portal only ever
// calls it on the `rest_base` a site reported (AD-6).
it('leaves ping and status to the connector, on the reported rest base', function () {
    expect(pathsHostedBy('{rest_base}'))->toBe(['/ping', '/status']);

    foreach (['/ping', '/status'] as $path) {
        $definition = contract()['paths'][$path];

        expect(methodsOf($definition))->toBe(['get'])
            ->and($definition['get']['responses']['200']['headers'][Contract::VERSION_HEADER]['$ref'])
            ->toBe('#/components/headers/ConnectorVersion');
    }
});

it('reads the site key from the header the contract names', function () {
    $scheme = contract()['components']['securitySchemes']['SiteKey'];

    expect($scheme['type'])->toBe('apiKey')
        ->and($scheme['in'])->toBe('header')
        ->and($scheme['name'])->toBe(Contract::SITE_KEY_HEADER);
});

it('reads the connector version from the header the contract names', function () {
    expect(contract()['components']['headers'])->toHaveKey('ConnectorVersion')
        ->and(array_values(array_unique(connectorVersionHeaderNames())))
        ->toBe([Contract::VERSION_HEADER]);
});

it('validates exactly the SiteReport the contract requires', function () {
    $contract = contract();

    expect($contract['paths']['/phone-home']['post']['requestBody']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/SiteReport')
        ->and((string) $contract['info']['version'])->toMatch('/^\d+\.\d+$/');

    $schema = $contract['components']['schemas']['SiteReport'];

    $top = array_values(array_unique(array_map(
        fn (string $rule): string => explode('.', $rule)[0],
        array_keys((new PhoneHomeRequest)->rules()),
    )));

    $required = $schema['required'];

    sort($top);
    sort($required);

    expect($top)->toBe($required);

    // The nested objects are part of the same body, so they are part of the
    // same guard.
    foreach (['theme', 'updates'] as $parent) {
        $nested = nestedRuleKeys($parent);
        $nestedRequired = $schema['properties'][$parent]['required'];

        sort($nested);
        sort($nestedRequired);

        expect($nested)->toBe($nestedRequired);
    }
});

it('answers the documented statuses on a registered route', function () {
    $responses = contract()['paths']['/phone-home']['post']['responses'];

    $documented = array_map('strval', array_keys($responses));
    $expected = ['200', '401', '422', '5XX'];

    sort($documented);
    sort($expected);

    expect($documented)->toBe($expected)
        ->and($responses['200']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/PhoneHomeAck');

    $posts = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => in_array('POST', $route->methods(), true))
        ->map(fn (Route $route): string => '/'.ltrim($route->uri(), '/'))
        ->all();

    expect($posts)->toContain(Contract::PATH_PREFIX.'/phone-home');
});

// AD-7 turns any 4xx into a day of silence, so a 429 from a shared client IP
// would silence an honest site. The route must carry no throttle at all.
it('never throttles the phone-home route', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn (Route $route): bool => $route->uri() === ltrim(Contract::PATH_PREFIX, '/').'/phone-home');

    expect($route)->not->toBeNull();

    $throttles = array_filter(
        $route->gatherMiddleware(),
        fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle'),
    );

    expect($throttles)->toBe([]);
});
