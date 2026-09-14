<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-Core-Matches, Johan's ruling 4 — an append-only, INTERNAL-only log of
 * every time an agent shares a buyer's live link. Never shown to the
 * buyer (rule 4: "all share history is internal"). Every row creation
 * also resets the buyer's working clock (rule 2) via
 * Contact::touchLastContacted() — see ::record().
 */
class ContactMatchShare extends Model
{
    use BelongsToAgency;

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
     * Record a share event and reset the buyer's working clock in the
     * same call — the two are the same fact from two angles (an internal
     * audit row, and the contact-wide "last touched" signal every other
     * feature already reads), so they are never recorded separately.
     */
    public static function record(ContactMatch $match, int $sharedByUserId, ?string $channel = null): self
    {
        $share = static::create([
            'agency_id'         => $match->agency_id,
            'contact_match_id'  => $match->id,
            'shared_by_user_id' => $sharedByUserId,
            'channel'           => $channel,
            'shared_at'         => now(),
        ]);

        $match->loadMissing('contact');
        $match->contact?->touchLastContacted($share->shared_at);

        return $share;
    }

    public function contactMatch(): BelongsTo
    {
        return $this->belongsTo(ContactMatch::class);
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }
}
