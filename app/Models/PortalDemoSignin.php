<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * QA DEMO SIGN-IN (never on Staging/live). A hashed, revocable, per-contact token that opens the tenant or owner portal in one click.
 * Spec: .ai/specs/rental-portal-access.md "QA demo sign-in". The token is shown once (when issued) and only its sha256 is stored.
 */
class PortalDemoSignin extends Model
{
    protected $table = 'portal_demo_signins';

    protected $fillable = ['agency_id', 'contact_id', 'role', 'token_hash', 'created_by_user_id', 'revoked_at', 'last_used_at', 'use_count'];

    protected $casts = ['revoked_at' => 'datetime', 'last_used_at' => 'datetime'];

    public const ROLES = ['owner', 'tenant'];

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Issue a new link for a contact and side; every earlier link for that contact and side is revoked. Returns the one-time token. */
    public static function issue(Contact $contact, string $role, ?int $byUserId = null): string
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Role must be owner or tenant.');
        }
        static::query()->where('contact_id', $contact->id)->where('role', $role)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $token = Str::random(48);
        static::query()->create([
            'agency_id' => $contact->agency_id, 'contact_id' => $contact->id, 'role' => $role,
            'token_hash' => self::hash($token), 'created_by_user_id' => $byUserId, 'use_count' => 0,
        ]);

        return $token;
    }

    /** The live (not revoked) record for a token, or null. */
    public static function findLive(string $token): ?self
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return static::query()->where('token_hash', self::hash($token))->whereNull('revoked_at')->first();
    }

    public static function revokeFor(int $contactId, ?string $role = null): int
    {
        return static::query()->where('contact_id', $contactId)->when($role, fn ($q) => $q->where('role', $role))->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
