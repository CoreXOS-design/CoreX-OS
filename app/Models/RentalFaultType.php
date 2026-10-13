<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rentals-faults-work-orders.md §2 — the agency-configurable
 * fault catalogue. is_default rows are CoreX's own seeded defaults
 * (RentalFaultType::seedDefaultsFor()); an agency may edit or archive its
 * own copy without a future reference-data sync silently reverting it —
 * seedDefaultsFor() is firstOrCreate-keyed, never an upsert/overwrite.
 */
class RentalFaultType extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const URGENCY_ROUTINE = 'routine';
    public const URGENCY_URGENT = 'urgent';
    public const URGENCY_EMERGENCY = 'emergency';

    /**
     * §2.1's safety line, prepended to every seeded default's first-aid
     * steps — never authored per-row, so it can never be accidentally
     * dropped from one fault type while present on the others.
     */
    public const SAFETY_LINE = 'If anyone is in danger, or there is fire, gas or live electrical '
        . 'exposure, leave the area and call emergency services first.';

    /**
     * §2.1 — CoreX's default fault list. Electrical/geyser steps never
     * instruct past switching a breaker at the DB board (§2.1's own
     * content rule — no default here describes opening a cover or
     * touching internal wiring).
     */
    public const DEFAULTS = [
        [
            'name' => 'Burst pipe / water leak',
            'category' => 'Plumbing',
            'urgency' => self::URGENCY_EMERGENCY,
            'first_aid_steps' => 'Your main water valve is at: {{main_water_valve_location}}. Close it '
                . 'now. Turn off any electrical appliances near the water — do not touch them if already '
                . 'wet.',
        ],
        [
            'name' => 'Power tripping / no power',
            'category' => 'Electrical',
            'urgency' => self::URGENCY_URGENT,
            'first_aid_steps' => 'Your DB board is at: {{db_board_location}}. Switch all circuit breakers '
                . 'off, then on one at a time to find the section that trips. Unplug every appliance on '
                . 'that section and try again. If it still trips, leave it off and log the fault.',
        ],
        [
            'name' => 'Geyser',
            'category' => 'Plumbing',
            'urgency' => self::URGENCY_URGENT,
            'first_aid_steps' => "Switch off the geyser's isolator switch at the DB board (switching "
                . 'only). Do not touch a leaking geyser element.',
        ],
        [
            'name' => 'Blocked drain / toilet',
            'category' => 'Plumbing',
            'urgency' => self::URGENCY_ROUTINE,
            'first_aid_steps' => 'Stop using the affected drain/toilet. Do not pour chemicals down it '
                . "before the agency's plumber has seen it.",
        ],
        [
            'name' => 'Gate / garage motor',
            'category' => 'Security',
            'urgency' => self::URGENCY_ROUTINE,
            'first_aid_steps' => 'Operate the gate/garage manually if a manual release exists; do not '
                . 'force the motor.',
        ],
        [
            'name' => 'Security / alarm',
            'category' => 'Security',
            'urgency' => self::URGENCY_EMERGENCY,
            'first_aid_steps' => 'If a break-in is suspected, do not enter — contact the agency and, if '
                . 'appropriate, the police first.',
        ],
        [
            'name' => 'Roof leak',
            'category' => 'Structural',
            'urgency' => self::URGENCY_URGENT,
            'first_aid_steps' => 'Move furniture/belongings away from the leak; place a container to '
                . 'catch water.',
        ],
        [
            'name' => 'Appliance',
            'category' => 'Appliance',
            'urgency' => self::URGENCY_ROUTINE,
            'first_aid_steps' => "Unplug the appliance at the wall if there's any smell of burning or "
                . 'visible damage — do not touch a damaged plug or cord.',
        ],
        [
            'name' => 'Lock / keys',
            'category' => 'Security',
            'urgency' => self::URGENCY_ROUTINE,
            'first_aid_steps' => 'Confirm which lock/door before reporting — helps the agency send the '
                . 'right locksmith.',
        ],
    ];

    protected $fillable = [
        'agency_id',
        'name',
        'category',
        'urgency',
        'first_aid_steps',
        'is_default',
        'sort_order',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The default fault TYPES only - exactly what this method has always done. The migration that CREATES the catalogue
     * (2026_09_29_183000) calls this one, because it runs long before the table that holds the "who normally pays" defaults
     * (2026_10_28_100000) exists: a migration must never depend on a later migration's table.
     */
    public static function seedDefaultTypesFor(int $agencyId): void
    {
        foreach (self::DEFAULTS as $i => $row) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'name' => $row['name']],
                [
                    'category' => $row['category'],
                    'urgency' => $row['urgency'],
                    'first_aid_steps' => self::SAFETY_LINE . "\n\n" . $row['first_aid_steps'],
                    'is_default' => true,
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * A new agency's whole default catalogue: the types AND (spec section 22.13.1) each type's "who normally pays" defaults.
     * Run-time use only (AgencyObserver, tests). Idempotent: every row is firstOrCreate-keyed and never overwritten, so an
     * agency's own edits or archived rows are left alone.
     */
    public static function seedDefaultsFor(int $agencyId): void
    {
        self::seedDefaultTypesFor($agencyId);
        static::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_default', true)->get()
            ->each(fn (self $type) => RentalFaultTypeCause::seedForType($type));
    }

    /** Spec section 22.13 - this type's self-checks and causes, each with a default "who normally pays". */
    public function causes(): HasMany
    {
        return $this->hasMany(RentalFaultTypeCause::class)->orderBy('sort_order')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RentalFaultTypeDocument::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * §2's own "archive/restore" floor plus "a fault report keeps its
     * catalogue reference even after archival" reasoning — no isDeletable()
     * gate on the destroy path is needed here the way rental_work_orders
     * has one, because this model is soft-delete only (never hard-deleted),
     * and a historical fault report's rental_fault_type_id FK survives
     * archival regardless (nullOnDelete is never used — see the migration).
     */
    public function archive(): void
    {
        $this->update(['is_active' => false]);
        $this->delete();
    }

    public function restoreRecord(): void
    {
        $this->update(['is_active' => true]);
        $this->restore();
    }
}
