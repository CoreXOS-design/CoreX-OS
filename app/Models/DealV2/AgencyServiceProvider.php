<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WS2 (AT-158 / DR2, D2) — a reusable agency service provider (electrician,
 * entomologist, transfer/bond attorney, bond originator, …). Agency-scoped,
 * soft-deleted (deactivate preserves historic deal references). Optionally
 * points at a CoreX contact but is not itself a contact type.
 */
class AgencyServiceProvider extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'contact_id',
        'name',
        'specialty',
        'is_transfer_attorney',
        'is_bond_attorney',
        'company',
        'registration_number',
        'email',
        'phone',
        'address',
        'notes',
        'is_preferred',
        'is_active',
        'created_by_id',
        'kind',
        'callout_fee_mode',
        'callout_fee_amount',
        'callout_fee_note',
    ];

    // .ai/specs/rentals-faults-work-orders.md §20 - the call-out fee. Callers use the methods below, never the columns.
    public const CALLOUT_YES = 'yes';
    public const CALLOUT_NO = 'no';
    public const CALLOUT_UNKNOWN = 'unknown';
    public const CALLOUT_MODES = [self::CALLOUT_YES, self::CALLOUT_NO, self::CALLOUT_UNKNOWN];

    /** 'yes' | 'no' | 'unknown' (a supplier nobody has asked is 'unknown'). */
    public function calloutFeeStatus(): string
    {
        return in_array($this->callout_fee_mode, self::CALLOUT_MODES, true) ? $this->callout_fee_mode : self::CALLOUT_UNKNOWN;
    }

    /** The fee in rand incl. VAT, or null unless the supplier charges one. */
    public function calloutFeeAmount(): ?float
    {
        return $this->calloutFeeStatus() === self::CALLOUT_YES && $this->callout_fee_amount !== null ? (float) $this->callout_fee_amount : null;
    }

    public function calloutFeeNote(): ?string
    {
        return $this->calloutFeeStatus() === self::CALLOUT_YES && trim((string) $this->callout_fee_note) !== '' ? trim((string) $this->callout_fee_note) : null;
    }

    /** The picker text: "call-out R750" / "no call-out fee" / "call-out fee not known". */
    public function calloutFeeSuffix(): string
    {
        return match ($this->calloutFeeStatus()) {
            self::CALLOUT_YES => 'call-out ' . self::formatRand((float) $this->calloutFeeAmount()),
            self::CALLOUT_NO => 'no call-out fee',
            default => 'call-out fee not known',
        };
    }

    /** The sentence for a notice ("Call-out fee: R750 (waived if the job goes ahead)"), or null unless the supplier charges one. */
    public function calloutFeeLine(): ?string
    {
        $amount = $this->calloutFeeAmount();
        if ($amount === null) {
            return null;
        }

        return 'Call-out fee: ' . self::formatRand($amount) . ($this->calloutFeeNote() ? ' (' . $this->calloutFeeNote() . ')' : '');
    }

    /** @param iterable<AgencyServiceProvider> $providers @return array<string, array{name:string, text:?string, status:string}> id => name, the inline call-out notice (null when there is nothing to say) and the answer */
    public static function noticeMap(iterable $providers): array
    {
        $map = [];
        foreach ($providers as $p) {
            $map[(string) $p->id] = ['name' => $p->name, 'text' => $p->calloutFeeNotice(), 'status' => $p->calloutFeeStatus()];
        }

        return $map;
    }

    /** R750, R1 200, R750.50 - whole rand without cents, a space for thousands. */
    public static function formatRand(float $amount): string
    {
        return 'R' . number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2, '.', ' ');
    }

    /** The inline notice shown when this supplier is chosen to go and inspect / quote; null when there is nothing to say (no fee). */
    public function calloutFeeNotice(): ?string
    {
        return match ($this->calloutFeeStatus()) {
            self::CALLOUT_YES => $this->name . ' charges a call-out fee of ' . self::formatRand((float) $this->calloutFeeAmount()) . '. The owner will be told.',
            self::CALLOUT_UNKNOWN => "We don't know if " . $this->name . ' charges a call-out fee - check before sending.',
            default => null,
        };
    }

    /**
     * AT-452 family (rentals-faults-work-orders.md section 21.1) - the KIND of supplier. Every existing row is a contractor. Kind does NOT change who
     * is offered as a repair contractor (see scopeMaintenanceContractors); it is shown beside the name and drives "Who looks at faults first".
     */
    public const KIND_CONTRACTOR = 'contractor';
    public const KINDS = [
        'contractor' => 'Contractor',
        'caretaker' => 'Caretaker / complex manager',
        'owner_team' => "Owner's own maintenance team",
        'insurer' => 'Insurer / insurance assessor',
        'body_corporate' => 'Body corporate / managing agent',
        // 13 Oct 2026 - property-transaction firms. They are NEVER offered as a rental contractor or a first responder (see NON_MAINTENANCE_KINDS).
        'attorney_conveyancer' => 'Attorney / conveyancer',
        'bond_originator' => 'Bond originator',
    ];

    /** Kinds that never do maintenance: kept out of every rental contractor picker and out of "Who looks at faults first", whatever their trade says. */
    public const NON_MAINTENANCE_KINDS = ['attorney_conveyancer', 'bond_originator'];

    /** The trades that make a firm an attorney / conveyancer (kind 'attorney_conveyancer'). */
    public const ATTORNEY_SPECIALTIES = ['transfer_attorney', 'bond_attorney', 'conveyancer'];

    /**
     * The kind a supplier should carry, given its trade: a Transfer / Bond attorney or conveyancer is an "Attorney / conveyancer", a bond originator is a
     * "Bond originator". Only a plain Contractor (or no kind) is re-derived - a kind somebody chose on purpose is never overridden.
     */
    public static function kindFor(?string $specialty, ?string $kind = null): string
    {
        $kind = $kind ?: self::KIND_CONTRACTOR;
        if ($kind !== self::KIND_CONTRACTOR) {
            return $kind;
        }

        return match (true) {
            in_array($specialty, self::ATTORNEY_SPECIALTIES, true) => 'attorney_conveyancer',
            $specialty === 'bond_originator' => 'bond_originator',
            default => $kind,
        };
    }

    protected static function booted(): void
    {
        // Every way of creating a supplier (directory form, deal inline add, deal register attorney firm) gets the right kind from its trade.
        static::creating(function (self $p) {
            $p->kind = self::kindFor($p->specialty, $p->kind);
        });
    }

    public static function kindLabel(?string $kind): string
    {
        return self::KINDS[$kind ?: self::KIND_CONTRACTOR] ?? self::KINDS[self::KIND_CONTRACTOR];
    }

    /**
     * AT-364 — the FIXED attorney capabilities and the boolean column each maps to. A firm can
     * hold BOTH (BBB does bonds and transfers). Distinct from the AT-319 agency-configurable
     * service types: these two roles never rename/archive, so they are plain booleans, not pivot
     * codes. The legacy single `specialty` enum stays the primary classifier and is untouched.
     */
    /**
     * The ONE bridge between an agency's service types (Settings -> Service types: the source of truth for a contractor's trade) and the legacy `specialty`
     * enum the property-sales side still reads (deal pipelines, DR work orders). A service type code/label -> the nearest legacy value; anything else is "other".
     * Never used to DECIDE a rental match - that reads the supplier's ticked service types - only to keep `specialty` filled in for the old pickers.
     */
    public static function legacySpecialtyForType(?string $code, ?string $label = null): string
    {
        $hay = strtolower(trim((string) $code . ' ' . (string) $label));
        return match (true) {
            str_contains($hay, 'plumb') => 'plumber',
            str_contains($hay, 'fence') => 'electric_fence',
            str_contains($hay, 'beetle'), str_contains($hay, 'entomolog') => 'entomologist',
            (bool) preg_match('/\bgas\b/', $hay) => 'gas',
            str_contains($hay, 'electr'), (bool) preg_match('/\bcoc\b/', $hay) => 'electrician',
            default => 'other',
        };
    }

    public const ATTORNEY_CAPABILITY_COLUMNS = [
        'transfer_attorney' => 'is_transfer_attorney',
        'bond_attorney'     => 'is_bond_attorney',
    ];

    /**
     * AT-217 (DR2 respec) — a firm has 1..n working contacts (attorney + contact
     * person). Active, non-deleted only for pickers; the pivot deal keeps its own FKs.
     */
    public function serviceContacts()
    {
        return $this->hasMany(AgencyServiceProviderContact::class, 'service_provider_id')->orderBy('attorney_name');
    }

    /**
     * AT-319 — the agency-configurable service types this supplier provides (a supplier
     * can be more than one). `service_type` is the AgencyServiceType code; soft-deleted
     * so un-ticking preserves history. The work-order panel filters the picker by these.
     */
    public function serviceTypes()
    {
        return $this->hasMany(AgencyServiceProviderServiceType::class, 'service_provider_id');
    }

    /** The active (non-trashed) service-type codes this supplier provides. */
    public function typeCodes(): array
    {
        return $this->serviceTypes->pluck('service_type')->unique()->values()->all();
    }

    protected $casts = [
        'callout_fee_amount' => 'decimal:2',
        'is_preferred' => 'boolean',
        'is_active' => 'boolean',
        'is_transfer_attorney' => 'boolean',
        'is_bond_attorney' => 'boolean',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForSpecialty(Builder $q, string $specialty): Builder
    {
        return $q->where('specialty', $specialty);
    }

    /**
     * AT-364 — the picker filter. For the two attorney specialties, surface any firm that
     * either carries the capability flag OR still holds the legacy specialty (belt-and-braces
     * so a not-yet-backfilled / edge row never vanishes). For every other specialty this is a
     * plain equality, identical to forSpecialty(). One code path, no behaviour change for
     * non-attorney pickers.
     */
    public function scopeCapableOf(Builder $q, string $specialty): Builder
    {
        $col = self::ATTORNEY_CAPABILITY_COLUMNS[$specialty] ?? null;
        if (! $col) {
            return $q->where('specialty', $specialty);
        }

        return $q->where(fn ($w) => $w->where($col, true)->orWhere('specialty', $specialty));
    }

    /**
     * AT-364 — any firm that is (or can act as) an attorney: used for inline dedup so
     * "add BBB as bond attorney" reuses the existing BBB transfer firm instead of duplicating.
     */
    public function scopeAnyAttorney(Builder $q): Builder
    {
        return $q->where(function ($w) {
            $w->where('is_transfer_attorney', true)
              ->orWhere('is_bond_attorney', true)
              ->orWhereIn('specialty', array_keys(self::ATTORNEY_CAPABILITY_COLUMNS));
        });
    }

    /**
     * Suppliers that do MAINTENANCE WORK - what a repair's quote, work order or invoice picker may offer (Johan, 8 Oct 2026: the lists were
     * offering conveyancing attorneys and bond originators). How supplier types are stored: `agency_service_providers.specialty` is a fixed set
     * (electrician, entomologist, plumber, gas, electric_fence, transfer_attorney, bond_attorney, conveyancer, bond_originator, external_agency,
     * other); a firm may ALSO carry the two attorney flags (`is_transfer_attorney`, `is_bond_attorney` - both at once allowed); and each agency
     * has its own configurable service types (`agency_service_types` via `agency_service_provider_service_types`: Electrical COC, Plumbing...).
     * Maintenance contractors = everyone who is NOT one of the property-transaction roles: no attorney flag, a kind that is not attorney / bond originator, and a specialty that is not
     * transfer_attorney / bond_attorney / conveyancer / bond_originator / external_agency. "other" stays in (an unclassified contractor).
     */
    public const NON_MAINTENANCE_SPECIALTIES = ['transfer_attorney', 'bond_attorney', 'conveyancer', 'bond_originator', 'external_agency'];

    public function scopeMaintenanceContractors(Builder $q): Builder
    {
        return $q->where('is_transfer_attorney', false)->where('is_bond_attorney', false)
            ->whereNotIn('specialty', self::NON_MAINTENANCE_SPECIALTIES)
            ->whereNotIn('kind', self::NON_MAINTENANCE_KINDS);
    }

    /** Validation twin of {@see self::scopeMaintenanceContractors()}: an id that exists, in this agency, and is a maintenance contractor (or $alsoAllowId, an existing choice left as it was). */
    public static function maintenanceExistsRule(int $agencyId, ?int $alsoAllowId = null): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('agency_service_providers', 'id')->where(function ($q) use ($agencyId, $alsoAllowId) {
            $q->where('agency_id', $agencyId)->whereNull('deleted_at')->where(function ($w) use ($alsoAllowId) {
                $w->where(fn ($m) => $m->where('is_transfer_attorney', 0)->where('is_bond_attorney', 0)->whereNotIn('specialty', self::NON_MAINTENANCE_SPECIALTIES)->whereNotIn('kind', self::NON_MAINTENANCE_KINDS));
                if ($alsoAllowId) {
                    $w->orWhere('id', $alsoAllowId);
                }
            });
        });
    }

    /** Preferred first, then alphabetical — the picker order. */
    public function scopePickerOrder(Builder $q): Builder
    {
        return $q->orderByDesc('is_preferred')->orderBy('name');
    }
}
