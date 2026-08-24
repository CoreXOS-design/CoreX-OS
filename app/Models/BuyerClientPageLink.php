<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The buyer-level Client Page link (Johan, 2026-08-24) — one per Contact,
 * permanent, resolved via the same /shared/match/{token} route every
 * per-wishlist contact_matches.share_slug link already uses. See the
 * migration for why this is a separate table from buyer_portal_links.
 */
class BuyerClientPageLink extends Model
{
    use BelongsToAgency;

    protected $fillable = ['agency_id', 'contact_id', 'slug'];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $link) {
            if (empty($link->slug)) {
                $link->slug = self::generateSlug($link);
            }
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public static function generateSlug(self $link): string
    {
        $contact = $link->relationLoaded('contact') ? $link->contact : Contact::withoutGlobalScopes()->find($link->contact_id);
        $base = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
        $base = $base !== '' ? Str::slug($base) : 'buyer';

        do {
            $candidate = $base . '-' . strtolower(Str::random(5));
            $exists = static::withoutGlobalScopes()->where('slug', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }

    public function url(): string
    {
        return route('shared.match', $this->slug);
    }
}
