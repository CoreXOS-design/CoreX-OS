<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Johan, 13 Oct 2026 - an agency's own groups for its service types (Settings -> Service types). Seeded per agency with the two that always existed on the supplier form:
 * "Your trades" and "Property sales". Add / rename / reorder / archive / restore; a group with active service types cannot be archived (nothing is ever orphaned).
 */
class AgencyServiceTypeGroup extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const CODE_TRADES = 'trades';
    public const CODE_PROPERTY_SALES = 'property_sales';

    public const DEFAULTS = [
        ['code' => self::CODE_TRADES, 'label' => 'Your trades'],
        ['code' => self::CODE_PROPERTY_SALES, 'label' => 'Property sales'],
    ];

    protected $table = 'agency_service_type_groups';

    protected $fillable = ['agency_id', 'code', 'label', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function types()
    {
        return $this->hasMany(AgencyServiceType::class, 'group_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Seed the two default groups for one agency, idempotently (raw write path with an explicit agency_id). */
    public static function seedDefaultsFor(int $agencyId): void
    {
        foreach (self::DEFAULTS as $i => $row) {
            static::withoutGlobalScopes()->firstOrCreate(['agency_id' => $agencyId, 'code' => $row['code']], ['label' => $row['label'], 'sort_order' => $i + 1, 'is_active' => true]);
        }
    }

    /** The agency's "Your trades" group id (created if the agency has none yet) - what a new service type falls into when no group is given. */
    public static function tradesIdFor(int $agencyId): int
    {
        static::seedDefaultsFor($agencyId);

        return (int) static::withoutGlobalScopes()->where('agency_id', $agencyId)->where('code', self::CODE_TRADES)->value('id');
    }

    /**
     * What the supplier Trade dropdown shows for the acting agency: the active groups in order, each with its active service types in order.
     * A role row (legacy_specialty set) is offered with its stored value; every other type is offered as "type:CODE".
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,code:string,label:string,options:\Illuminate\Support\Collection}>
     */
    public static function dropdown(): \Illuminate\Support\Collection
    {
        $types = AgencyServiceType::offered()->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'label', 'group_id', 'legacy_specialty'])->groupBy('group_id');

        return static::active()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($g) => [
            'id' => (int) $g->id, 'code' => (string) $g->code, 'label' => (string) $g->label,
            'options' => ($types[$g->id] ?? collect())->map(fn ($t) => ['code' => $t->code, 'label' => $t->label, 'value' => $t->legacy_specialty ?: 'type:' . $t->code, 'role' => $t->legacy_specialty !== null])->values(),
        ])->filter(fn ($g) => $g['options']->isNotEmpty())->values();
    }
}
