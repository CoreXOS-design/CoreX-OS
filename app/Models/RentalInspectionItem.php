<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rental-inspections.md §3.1/§3.2 — the actual thing being watched
 * over time: a whole space ("Bedroom 1") or something finer inside it. An
 * item has no condition field itself — "current condition" is always a
 * query over its observations, never a mutable column (§3.1).
 *
 * Property-scoped, never lease-scoped — a physical space outlives any one
 * tenancy. This is what lets an out-inspection carry forward a fault
 * reported under a PREVIOUS tenant (§0.2) and what lets a future work
 * order (.ai/specs/rental-work-orders.md) reach a space's full cross-
 * tenancy history via `rental_inspection_item_id`.
 */
class RentalInspectionItem extends Model
{
    use BelongsToAgency;

    public const KIND_SPACE = 'space';
    public const KIND_METER = 'meter';

    protected $fillable = [
        'agency_id',
        'property_id',
        'property_room_id',
        'kind',
        'label',
        'space_type',
        'source',
        'sort_order',
        'is_retired',
        'created_by_user_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_retired' => 'boolean',
    ];

    /** Deleted-related-record rule (.ai/BUILD_STANDARD.md §4) — see Lease::property(). */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    /**
     * Stage 2 — which PropertyRoom this facet belongs to. Only ever set for
     * kind='space' rows; a meter has no room. Nullable so pre-Stage-2 rows
     * (created before this column existed) and meters both resolve to null
     * without needing a backfill.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(PropertyRoom::class, 'property_room_id');
    }

    /** No hardcoded order here — callers (currentObservation(), fullHistory()) apply their own. */
    public function observations(): HasMany
    {
        return $this->hasMany(RentalInspectionObservation::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(RentalInspectionDiscrepancy::class);
    }

    public function scopeNotRetired(Builder $q): Builder
    {
        return $q->where('is_retired', false);
    }

    /**
     * §3.2, Johan (property 4862): "how do I add to a room, not a new
     * room." A single facet added to an ALREADY-EXISTING room — not a new
     * room, no checklist reseed. Behaves exactly like a vocabulary-seeded
     * facet (kind=space, same room, same observation/discrepancy/photo
     * machinery) because it IS one; only its origin differs.
     * `source='manual'` matches createRoomChecklist()'s own manually-added
     * rooms — there is no separate provenance flag to drift out of sync.
     */
    public static function addToRoom(PropertyRoom $room, string $label, User $by): self
    {
        $nextSortOrder = ((int) static::where('property_room_id', $room->id)->max('sort_order')) + 1;

        return static::create([
            'agency_id' => $room->agency_id,
            'property_id' => $room->property_id,
            'property_room_id' => $room->id,
            'kind' => self::KIND_SPACE,
            'label' => $label,
            'space_type' => $room->type,
            'source' => 'manual',
            'sort_order' => $nextSortOrder,
            'created_by_user_id' => $by->id,
        ]);
    }

    /**
     * §45.4 item 2 (Build I-2) — "Add missing standard items". A property's
     * checklist is seeded ONCE and never re-reads the agency template (§19),
     * so a room built before the template improved stays thin. This is the
     * diff an agent confirms before anything changes: which of the agency's
     * current default items for this room's type the room does not already
     * have.
     *
     * A template item counts as already there if ANY item on the room — live
     * or retired — carries the same label, compared case- and space-
     * insensitively. Retired counts deliberately: an agent who retired "Skirting"
     * made a decision, and a top-up must never quietly undo it. Those are
     * returned as `present` so the screen can say so rather than hide them.
     *
     * @return array{missing: array<int, string>, present: array<int, string>}
     */
    public static function standardItemDiffFor(PropertyRoom $room): array
    {
        $norm = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));

        $have = static::where('property_room_id', $room->id)->pluck('label')
            ->map(fn ($l) => $norm((string) $l))->flip();

        $missing = [];
        $present = [];
        $seen = [];
        foreach (RentalInspectionSetting::roomTypeItemsFor($room->agency_id, (string) $room->type) as $label) {
            $label = trim(preg_replace('/\s+/u', ' ', (string) $label));
            $key = $norm($label);
            if ($label === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($have->has($key)) {
                $present[] = $label;
            } else {
                $missing[] = $label;
            }
        }

        return ['missing' => $missing, 'present' => $present];
    }

    /**
     * §45.4 item 2 — apply the top-up for the labels the agent confirmed.
     * Only labels that are STILL missing right now are added (recomputed
     * server-side — a stale preview, a replayed request or a tampered label
     * can never add anything that is not a current template item the room
     * lacks), each through addToRoom(): appended after the room's last item,
     * so existing items are never reordered, retired or touched, and no
     * observation is read or written. Safe to repeat — a second call finds
     * nothing missing and adds nothing.
     *
     * @param  array<int, string>  $confirmedLabels
     * @return array<int, self> the items created
     */
    public static function addMissingStandardItems(PropertyRoom $room, array $confirmedLabels, User $by): array
    {
        $norm = fn (string $s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
        $confirmed = collect($confirmedLabels)->map(fn ($l) => $norm((string) $l))->flip();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($room, $confirmed, $by, $norm) {
            $created = [];
            foreach (self::standardItemDiffFor($room)['missing'] as $label) {
                if ($confirmed->has($norm($label))) {
                    $created[] = self::addToRoom($room, $label, $by)->load('room');
                }
            }

            return $created;
        });
    }

    /** Display text only — never touches history, observations reference item_id, not label. */
    public function rename(string $label): void
    {
        $this->update(['label' => $label]);
    }

    /** §3.3 — is_retired is this table's soft-delete floor (never deleted_at, see the spec's own reasoning). */
    public function restoreItem(): void
    {
        $this->update(['is_retired' => false]);
    }

    /**
     * §3.1 — "current condition is a query, never a column." The most
     * recent observation for this item that isn't sitting inside an
     * unresolved discrepancy, and never the LOSING side of a resolved one —
     * once a discrepancy is resolved, only its accepted_observation_id may
     * still count as current; the losing participant stays in the table
     * (never touched, §0.4) but must never outrank the accepted one just
     * because its created_at happens to sort later (these columns are
     * whole-second precision, so same-request ties are real). Deliberately
     * reads the WHOLE history (no lease filter) — an item's "current
     * condition" is a property-level fact, independent of which tenancy
     * last touched it.
     */
    public function currentObservation(): ?RentalInspectionObservation
    {
        $excludedObservationIds = $this->discrepancies()
            ->with('observations:id')
            ->get()
            ->flatMap(function (RentalInspectionDiscrepancy $discrepancy) {
                $participantIds = $discrepancy->observations->pluck('id');

                return is_null($discrepancy->resolved_at)
                    ? $participantIds
                    : $participantIds->reject(fn ($id) => $id === $discrepancy->accepted_observation_id);
            });

        // AT-433, 2026-09-26 — a photo-anchor row (RentalInspectionObservation
        // ::CONDITION_PENDING) is not a condition anyone assessed; ->recorded()
        // skips it so "current condition" falls through to an earlier real
        // one (or null), never surfaces an empty string as if it were a fact.
        return $this->observations()
            ->recorded()
            ->when($excludedObservationIds->isNotEmpty(), fn (Builder $q) => $q->whereNotIn('id', $excludedObservationIds->unique()))
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * §0.2/§3.2a — every observation ever recorded against this item,
     * across every lease this property has ever had, in order. The
     * cross-tenant carry-forward query, and the same shape (minus the
     * lease filter) as the within-this-tenancy comprehensive log.
     */
    public function fullHistory(): HasMany
    {
        return $this->hasMany(RentalInspectionObservation::class)->oldest('created_at');
    }
}
