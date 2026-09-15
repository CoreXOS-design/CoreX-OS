<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\Matching\ClientMatchResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AT-Core-Matches, Johan's dated-link ruling — a share no longer reuses one
 * static permanent link forever; every time an agent composes a WhatsApp/
 * Email send, a NEW row here mints a fresh, independently resolvable link
 * carrying its own generation date ("shared_at"). Every link ever minted
 * keeps resolving live (SharedMatchController) until the buyer is Won or
 * Lost — nothing here ever expires a link by age.
 *
 * TWO-PHASE: mint() creates the row the moment the composer renders (so the
 * fresh link is already the one embedded in the message text the agent
 * reads/edits) WITHOUT touching the buyer's working clock or snapshotting
 * anything yet — opening the composer and never sending must have zero
 * side effects. confirmSent() is the actual send action (button click) and
 * is what performs the real effects: stamps the channel, snapshots the
 * live match set (so "what's new since" has a true baseline), and resets
 * the buyer's working clock. confirmed_at (not channel-is-null — channel
 * was already nullable for a real reason: a copy-link share where we don't
 * know which app it landed in) is the authoritative gate everywhere a
 * reader asks "did this share actually happen" — a minted-but-abandoned
 * row must never count as a share, appear in "last shared", or contribute
 * to the property snapshot.
 *
 * Never shown to the buyer (Johan's original ruling 4: "all share history
 * is internal") — only which properties are new is ever surfaced to them,
 * never the fact/date/channel of a share itself. SoftDeletes because a
 * confirmed row is evidence ("what did we put in front of that buyer in
 * March") and every evidentiary record in CoreX is soft-delete only.
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
        'token',
        'channel',
        'shared_at',
        'confirmed_at',
    ];

    protected $casts = [
        'shared_at'    => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    /**
     * Mint a fresh, dated link for this match — called when the share
     * composer renders, not when it's sent. Pure creation, no side
     * effects: no property snapshot, no working-clock touch. If the agent
     * never actually sends, this row simply stays unconfirmed forever —
     * harmless, and invisible to every reader that filters on confirmed_at.
     */
    public static function mint(ContactMatch $match, int $mintedByUserId): self
    {
        return static::create([
            'agency_id'         => $match->agency_id,
            'contact_match_id'  => $match->id,
            'shared_by_user_id' => $mintedByUserId,
            'token'             => (string) Str::ulid(),
            'shared_at'         => now(),
        ]);
    }

    /**
     * The actual send action. Snapshots the live match set (the EXACT same
     * query the link itself runs, via ClientMatchResolver::resolve() —
     * never a second, parallel query) so the recorded baseline is provably
     * what the buyer would have seen had they opened the link at that
     * instant, and resets the buyer's working clock. Idempotent: confirming
     * an already-confirmed share (a double-click, a retry) just returns it
     * unchanged rather than double-snapshotting or re-touching the clock.
     */
    public function confirmSent(?string $channel = null): self
    {
        if ($this->confirmed_at !== null) {
            return $this;
        }

        return DB::transaction(function () use ($channel) {
            $this->forceFill([
                'channel'      => $channel,
                'confirmed_at' => now(),
            ])->save();

            $match = $this->contactMatch()->withoutGlobalScopes()->first();
            $propertyIds = $match ? app(ClientMatchResolver::class)->resolve($match, false)->pluck('id') : collect();
            if ($propertyIds->isNotEmpty()) {
                $now = $this->confirmed_at;
                ContactMatchShareProperty::insert($propertyIds->map(fn (int $propertyId) => [
                    'agency_id'               => $this->agency_id,
                    'contact_match_id'        => $this->contact_match_id,
                    'contact_match_share_id'  => $this->id,
                    'property_id'             => $propertyId,
                    'created_at'              => $now,
                ])->all());
            }

            $match?->loadMissing('contact');
            $match?->contact?->touchLastContacted($this->confirmed_at);

            return $this;
        });
    }

    public function url(): string
    {
        return route('shared.match', $this->token);
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
