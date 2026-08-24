<?php

namespace App\Http\Requests\Connector;

use App\Connector\Rules\PublicHost;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the `SiteReport` body exactly as `openapi.yaml` types it.
 *
 * Unknown fields are ignored, not stored (AD-8): the controller stores
 * `validated()`, so anything the rules do not name drops out.
 *
 * The top-level rule keys are the schema's `required` list, and the dotted
 * keys are the nested ones — `ContractTest` asserts both, so they cannot drift
 * apart.
 *
 * `present` rather than `required` on every plain string: the contract sets no
 * `minLength`, and a WordPress site can genuinely have an empty title. The
 * connector prefix keeps `ConvertEmptyStringsToNull` off, so an empty string
 * arrives as an empty string and is stored as one.
 */
class PhoneHomeRequest extends FormRequest
{
    /** The longest value the `sites` string columns hold. */
    private const MAX_STRING = 255;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `\z` not `$`: `$` also matches before a trailing newline.
            'connector_version' => ['required', 'string', 'max:'.self::MAX_STRING, 'regex:/^\d+\.\d+\.\d+\z/'],
            // `site_url` is where WordPress core lives; the portal never
            // fetches it, so it is not held to the public-host rule.
            'site_url' => $this->urlRules(),
            'home_url' => [...$this->urlRules(), new PublicHost],
            // The one URL the portal ever calls back on (AD-6), and the reason
            // the public-host rule exists.
            'rest_base' => [...$this->urlRules(), new PublicHost, $this->rejectQueryOrFragment()],
            'site_name' => ['present', 'string'],
            'wp_version' => ['present', 'string'],
            'php_version' => ['present', 'string'],
            'locale' => ['present', 'string'],
            'timezone' => ['present', 'string'],
            // Strict: the contract types this as a JSON boolean, so `1` and
            // `"1"` are not the same fact.
            'multisite' => ['required', 'boolean:strict'],
            'theme' => ['required', 'array'],
            'theme.slug' => ['present', 'string'],
            'theme.name' => ['present', 'string'],
            // The contract allows a theme that declares no version.
            'theme.version' => ['present', 'string'],
            'updates' => ['required', 'array'],
            'updates.wordpress' => $this->countRules(),
            'updates.plugins' => $this->countRules(),
            'updates.themes' => $this->countRules(),
        ];
    }

    /**
     * A reported URL: http(s) only, and short enough for its column.
     *
     * @return array<int, string>
     */
    private function urlRules(): array
    {
        return ['required', 'string', 'max:'.self::MAX_STRING, 'url:http,https'];
    }

    /**
     * A pending-update count, as the contract types it: a JSON integer, >= 0.
     *
     * @return array<int, string>
     */
    private function countRules(): array
    {
        return ['required', 'integer:strict', 'min:0'];
    }

    /**
     * The portal appends `/ping` and `/status` to `rest_base`, so a query
     * string or a fragment there would build a URL nobody meant.
     */
    private function rejectQueryOrFragment(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parts = is_string($value) ? parse_url($value) : [];

            if (isset($parts['query']) || isset($parts['fragment'])) {
                $fail('The rest_base must not carry a query string or fragment.');
            }
        };
    }
}
