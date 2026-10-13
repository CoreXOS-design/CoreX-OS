<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.13 - one self-check or one possible cause of a fault type, with the default answer to
 * "if this was the cause, who normally pays". A default is only a SUGGESTION shown to the agent; the ruling is the agent's. Soft delete only.
 * Seeded neutral defaults live here (DEFAULTS) so the migration back-fill and a new agency's seeding use the same list.
 */
class RentalFaultTypeCause extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const KIND_SELF_CHECK = 'self_check';
    public const KIND_CAUSE = 'cause';
    public const KINDS = [self::KIND_SELF_CHECK => 'Self-check', self::KIND_CAUSE => 'Cause'];

    /** The most rows one fault type may carry per kind (a stated cap - this is a short list by design). */
    public const MAX_PER_KIND = 20;

    protected $fillable = ['agency_id', 'rental_fault_type_id', 'kind', 'label', 'default_payer', 'sort_order', 'is_active', 'created_by_user_id'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    /**
     * Seeded defaults, by default fault-type NAME. 'Wear and tear' is added to every default type (see seedForType()); "Something else (describe it)"
     * is not a row - it is always offered last on the report-back, with a free-text box.
     * [kind, label, default payer (null = no default)]
     */
    public const DEFAULTS = [
        'Gate / garage motor' => [[self::KIND_SELF_CHECK, 'Remote control battery is flat', RentalPayerRuling::PAYER_TENANT]],
        'Geyser' => [[self::KIND_SELF_CHECK, 'Gas geyser batteries are flat', RentalPayerRuling::PAYER_TENANT]],
        'Appliance' => [[self::KIND_SELF_CHECK, 'Gas bottle is empty', RentalPayerRuling::PAYER_TENANT]],
        'Blocked drain / toilet' => [
            [self::KIND_CAUSE, 'Foreign object in the drain', RentalPayerRuling::PAYER_TENANT],
            [self::KIND_CAUSE, 'Roots in the pipe', RentalPayerRuling::PAYER_OWNER],
            [self::KIND_CAUSE, 'Collapsed pipe', RentalPayerRuling::PAYER_OWNER],
        ],
    ];

    public const COMMON = [
        [self::KIND_CAUSE, 'Wear and tear', RentalPayerRuling::PAYER_OWNER],
    ];

    /** Seed (idempotently - firstOrCreate, never an overwrite) the defaults for ONE default fault type. */
    public static function seedForType(RentalFaultType $type): void
    {
        $rows = array_merge(self::DEFAULTS[$type->name] ?? [], self::COMMON);
        foreach ($rows as $i => [$kind, $label, $payer]) {
            static::withoutGlobalScopes()->withTrashed()->firstOrCreate(
                ['agency_id' => $type->agency_id, 'rental_fault_type_id' => $type->id, 'kind' => $kind, 'label' => $label],
                ['default_payer' => $payer, 'sort_order' => $i + 1, 'is_active' => true],
            );
        }
    }

    public function faultType(): BelongsTo
    {
        return $this->belongsTo(RentalFaultType::class, 'rental_fault_type_id')->withoutGlobalScopes()->withTrashed();
    }

    public function payerLabel(): string
    {
        return RentalPayerRuling::defaultLabel($this->default_payer);
    }

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

    /** Case, spacing and a trailing full stop do not matter when a tenant's tick is matched to a self-check row. */
    public static function normalise(string $text): string
    {
        return rtrim(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text))), ". \t");
    }
}
