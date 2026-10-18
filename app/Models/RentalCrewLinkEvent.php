<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §14.29 — append-only audit row for a crew's
 * standing link (the crew page). Office actions carry `actor_user_id`; what the
 * crew does carries the request's IP + device. Never edited, never deleted.
 */
class RentalCrewLinkEvent extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const EVENT_ISSUED = 'issued';
    public const EVENT_REGENERATED = 'regenerated';
    public const EVENT_EMAILED = 'emailed';
    public const EVENT_REVOKED = 'revoked';
    public const EVENT_OPENED = 'opened';
    public const EVENT_JOB_OPENED = 'job_opened';
    public const EVENT_ACTION = 'action';

    /** One "opened" row per token per this many minutes (last_used_at still updates on every open). */
    public const OPEN_THROTTLE_MINUTES = 10;

    protected $fillable = [
        'agency_id', 'rental_crew_id', 'token_id', 'event', 'rental_job_card_id',
        'note', 'ip', 'user_agent', 'actor_user_id', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function crew(): BelongsTo
    {
        return $this->belongsTo(RentalCrew::class, 'rental_crew_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id')->withTrashed();
    }

    /**
     * Write one event. `agency_id` is always passed explicitly (a public crew
     * request has no staff user for the trait to stamp from), and the model is
     * created with global scopes off so it never depends on who is asking.
     */
    public static function record(
        string $event,
        int $agencyId,
        int $crewId,
        ?int $tokenId = null,
        ?int $jobCardId = null,
        ?string $note = null,
        ?User $actor = null,
        ?Request $request = null,
    ): self {
        return static::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'rental_crew_id' => $crewId,
            'token_id' => $tokenId,
            'event' => $event,
            'rental_job_card_id' => $jobCardId,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent() !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'actor_user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Has this token already logged an "opened" row inside the throttle
     * window? (Throttle is on the log row only; the card's `last_used_at`
     * updates on every open regardless.)
     */
    public static function recentlyOpened(int $tokenId, string $event = self::EVENT_OPENED, ?int $jobCardId = null): bool
    {
        return static::withoutGlobalScopes()
            ->where('token_id', $tokenId)
            ->where('event', $event)
            ->when($jobCardId !== null, fn ($q) => $q->where('rental_job_card_id', $jobCardId))
            ->where('created_at', '>=', now()->subMinutes(self::OPEN_THROTTLE_MINUTES))
            ->exists();
    }
}
