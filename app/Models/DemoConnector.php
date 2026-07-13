<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The one credential the demo instance uses to talk to primary.
 *
 * Spec: .ai/specs/demo-access-control.md §5.1
 *
 * Universal, not per-agency (see the migration docblock for why). Lives on PRIMARY;
 * the demo holds only the plaintext, pasted into its Demo Connection page.
 *
 * Rotation is INSERT + revoke-the-old, never UPDATE — the table doubles as the audit
 * trail of every credential the demo has held. At most one row is un-revoked.
 */
class DemoConnector extends Model
{
    protected $fillable = [
        'name',
        'key_prefix',
        'secret_hash',
        'last_used_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    /** The hash is a credential. It never leaves the server. */
    protected $hidden = ['secret_hash'];

    /**
     * Mint the connector, revoking any predecessor.
     *
     * Returns [$connector, $plaintext]. The plaintext exists HERE and on the
     * one-time confirmation screen, and nowhere else — the row holds sha256 only.
     *
     * Revoking the old row in the same breath is what enforces "at most one active":
     * a rotation must not leave the previous token quietly working, or rotating in
     * response to a leak would achieve nothing.
     */
    public static function mint(string $name, ?int $userId = null): array
    {
        static::active()->each(fn (self $old) => $old->revoke());

        $prefix = 'cx_demo_' . Str::lower(Str::random(8));
        $secret = Str::random(48);

        $connector = static::create([
            'name'        => trim($name) !== '' ? trim($name) : 'Demo connector',
            'key_prefix'  => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'created_by'  => $userId,
        ]);

        return [$connector, $prefix . '.' . $secret];
    }

    /**
     * Resolve a presented bearer token to the active connector.
     *
     * Token format: "<prefix>.<secret>" — the prefix narrows to one row (indexed,
     * no table scan) and the secret is then compared in constant time.
     *
     * Returns null on ANY failure — malformed, unknown prefix, revoked, wrong
     * secret. The caller cannot distinguish, and must not: a 401 that says WHICH
     * part was wrong is an oracle.
     */
    public static function resolve(?string $token): ?self
    {
        if (! $token || ! str_contains($token, '.')) {
            return null;
        }

        [$prefix, $secret] = explode('.', $token, 2);

        $connector = static::active()->where('key_prefix', $prefix)->first();

        if (! $connector || ! $connector->verifySecret($secret)) {
            return null;
        }

        return $connector;
    }

    public function verifySecret(string $secret): bool
    {
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public function scopeActive($q)
    {
        return $q->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => Carbon::now()])->save();
        }
    }

    /** The active connector, or null if the demo has never been wired up. */
    public static function current(): ?self
    {
        return static::active()->orderByDesc('id')->first();
    }

    /**
     * Throttled to once a minute. The demo gate calls the API on (almost) every
     * page load; writing this row each time would hammer it for no extra signal.
     */
    public function markUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(Carbon::now()->subMinute())) {
            $this->forceFill(['last_used_at' => Carbon::now()])->saveQuietly();
        }
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
