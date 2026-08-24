<?php

namespace App\Models;

use App\Connector\SiteKey;
use Database\Factories\SiteFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One registered client site (AD-6).
 *
 * Rows are created only by `site:onboard`. `home_url`, `rest_base`,
 * `connector_version`, `last_seen_at` and `last_report` are written only by a
 * phone-home.
 */
// Only the one value a human types. Everything a phone-home owns is written
// with `forceFill()` by the controller, and the key pair by `issueKey()`.
#[Fillable(['site_url'])]
#[Hidden(['site_key', 'site_key_hash'])]
class Site extends Model implements Authenticatable
{
    /** @use HasFactory<SiteFactory> */
    use AuthenticatableTrait, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'site_key' => 'encrypted',
            'last_report' => 'array',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /**
     * Resolves the site a presented key belongs to.
     *
     * Format check first, then the indexed hash lookup, then `hash_equals()`
     * against the decrypted key (AD-5). A row is never found by decrypting.
     */
    public static function findByKey(?string $key): ?self
    {
        if (! SiteKey::isValidFormat($key)) {
            return null;
        }

        $site = static::query()->where('site_key_hash', SiteKey::hash($key))->first();

        if ($site === null) {
            return null;
        }

        try {
            $stored = (string) $site->site_key;
        } catch (DecryptException) {
            // A row encrypted under a retired APP_KEY. It is not this caller's
            // to fix, and it must not turn a 401 into a 500.
            return null;
        }

        return hash_equals($stored, (string) $key) ? $site : null;
    }

    /**
     * Issues a fresh key for this site and returns the plaintext once.
     *
     * The old key dies the same second: the row is saved before this returns.
     */
    public function issueKey(): string
    {
        $key = SiteKey::generate();

        $this->forceFill([
            'site_key' => $key,
            'site_key_hash' => SiteKey::hash($key),
        ])->save();

        return $key;
    }
}
