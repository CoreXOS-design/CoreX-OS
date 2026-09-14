<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\Matching\ClientMatchResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * AT-Core-Matches, Johan's ruling 4 — an append-only, INTERNAL-only log of
 * every time an agent shares a buyer's live link. Never shown to the
 * buyer (rule 4: "all share history is internal"). Every row creation
 * also resets the buyer's working clock (rule 2) via
 * Contact::touchLastContacted() — see ::record().
 *
 * Share-history piece — ALSO snapshots which properties the match's live
 * query returned at that exact moment (never edited/recalculated after —
 * see ContactMatchShareProperty's own docblock). SoftDeletes because this
 * row is evidence ("what did we put in front of that buyer in March") and
 * every evidentiary record in CoreX is soft-delete only.
 */
class ContactMatchShare extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const UPDATED_AT = null;

    public const CHANNEL_COPY_LINK = 'copy_link';
    public const CHANNEL_WHATSAPP  = 'whatsapp';
    public const CHANNEL_EMAIL     = 'email';
    public const CHANNEL_OTHER     = 'other';

    protected $fillable = [
        'agency_id',
        'contact_match_id',
        'shared_by_user_id',
        'channel',
        'shared_at',
    ];

    protected $casts = [
        'shared_at' => 'datetime',
    ];

    /**
     * Record a share event, snapshot the live match set it shared, and
     * reset the buyer's working clock — all in one transaction, because
     * all three are the same fact from different angles. The property
     * snapshot is resolved via ClientMatchResolver::resolve(), the EXACT
     * same query the live link itself runs (never a second, parallel
     * query) — so the recorded set is provably what the buyer would have
     * seen had they opened the link at that instant.
     */
    public static function record(ContactMatch $match, int $sharedByUserId, ?string $channel = null): self
    {
        return DB::transaction(function () use ($match, $sharedByUserId, $channel) {
            $share = static::create([
                'agency_id'         => $match->agency_id,
                'contact_match_id'  => $match->id,
                'shared_by_user_id' => $sharedByUserId,
                'channel'           => $channel,
                'shared_at'         => now(),
            ]);

            $propertyIds = app(ClientMatchResolver::class)->resolve($match, false)->pluck('id');
            if ($propertyIds->isNotEmpty()) {
                $now = $share->shared_at;
                ContactMatchShareProperty::insert($propertyIds->map(fn (int $propertyId) => [
                    'agency_id'               => $match->agency_id,
                    'contact_match_id'        => $match->id,
                    'contact_match_share_id'  => $share->id,
                    'property_id'             => $propertyId,
                    'created_at'              => $now,
                ])->all());
            }

            $match->loadMissing('contact');
            $match->contact?->touchLastContacted($share->shared_at);

            return $share;
        });
    }

    public function contactMatch(): BelongsTo
    {
        return $this->belongsTo(ContactMatch::class);
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(ContactMatchShareProperty::class);
    }
}
