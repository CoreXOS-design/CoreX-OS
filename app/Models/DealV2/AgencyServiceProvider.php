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
    ];

    /**
     * AT-364 — the FIXED attorney capabilities and the boolean column each maps to. A firm can
     * hold BOTH (BBB does bonds and transfers). Distinct from the AT-319 agency-configurable
     * service types: these two roles never rename/archive, so they are plain booleans, not pivot
     * codes. The legacy single `specialty` enum stays the primary classifier and is untouched.
     */
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
     * Maintenance contractors = everyone who is NOT one of the property-transaction roles: no attorney flag, and a specialty that is not
     * transfer_attorney / bond_attorney / conveyancer / bond_originator / external_agency. "other" stays in (an unclassified contractor).
     */
    public const NON_MAINTENANCE_SPECIALTIES = ['transfer_attorney', 'bond_attorney', 'conveyancer', 'bond_originator', 'external_agency'];

    public function scopeMaintenanceContractors(Builder $q): Builder
    {
        return $q->where('is_transfer_attorney', false)->where('is_bond_attorney', false)
            ->whereNotIn('specialty', self::NON_MAINTENANCE_SPECIALTIES);
    }

    /** Validation twin of {@see self::scopeMaintenanceContractors()}: an id that exists, in this agency, and is a maintenance contractor (or $alsoAllowId, an existing choice left as it was). */
    public static function maintenanceExistsRule(int $agencyId, ?int $alsoAllowId = null): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists('agency_service_providers', 'id')->where(function ($q) use ($agencyId, $alsoAllowId) {
            $q->where('agency_id', $agencyId)->whereNull('deleted_at')->where(function ($w) use ($alsoAllowId) {
                $w->where(fn ($m) => $m->where('is_transfer_attorney', 0)->where('is_bond_attorney', 0)->whereNotIn('specialty', self::NON_MAINTENANCE_SPECIALTIES));
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
