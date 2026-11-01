<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The token an external auction website keeps for a signed-in buyer (.ai/specs/auctions.md §24). Only a sha256 hash is stored; the plain token is
 * shown once, at sign-up / login. It belongs to ONE buyer at ONE agency, expires, and can be revoked - and it is accepted by the auction website
 * API only (never by the phone app or the rental portal), so it opens nothing else.
 */
class AuctionBuyerToken extends Model
{
    public const LIFETIME_DAYS = 30;

    protected $fillable = ['client_user_id', 'agency_id', 'api_key_id', 'token_hash', 'ip', 'last_used_at', 'expires_at', 'revoked_at'];

    protected $casts = ['last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    /** @return array{0: self, 1: string} the stored row and the plain token (never recoverable again) */
    public static function issue(ClientUser $user, int $agencyId, ?int $apiKeyId, ?string $ip): array
    {
        $plain = 'cxb_' . Str::random(56);
        $row = static::create([
            'client_user_id' => $user->id, 'agency_id' => $agencyId, 'api_key_id' => $apiKeyId, 'ip' => $ip,
            'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return [$row, $plain];
    }

    /** The live token for $plain at this agency, or null (unknown, expired, revoked, or another agency's). */
    public static function find(string $plain, int $agencyId): ?self
    {
        if (! str_starts_with($plain, 'cxb_')) {
            return null;
        }
        $row = static::query()->where('token_hash', hash('sha256', $plain))->first();
        if (! $row || (int) $row->agency_id !== $agencyId || $row->revoked_at || ($row->expires_at && $row->expires_at->isPast())) {
            return null;
        }

        return $row;
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function buyer()
    {
        return $this->belongsTo(ClientUser::class, 'client_user_id');
    }
}
