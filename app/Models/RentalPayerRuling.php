<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.13 - the agent's LOGGED ruling on who pays for a fault repair. A record, not a charge: nothing
 * is invoiced or posted. Append-only: a newer ruling supersedes an older one (superseded_at / superseded_by_id) and `owner_told_at` / `tenant_told_at`
 * are stamped once the email went; no other column is written after creation. Never deleted.
 */
class RentalPayerRuling extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const PAYER_OWNER = 'owner';
    public const PAYER_TENANT = 'tenant';
    public const PAYER_SHARED = 'shared';
    public const PAYER_INSURANCE = 'insurance_body_corporate';

    /** What an agent may rule. */
    public const PAYERS = [
        self::PAYER_OWNER => 'Owner',
        self::PAYER_TENANT => 'Tenant',
        self::PAYER_SHARED => 'Shared',
        self::PAYER_INSURANCE => 'Insurance or body corporate',
    ];

    public const EVIDENCE_MAX_KB = 10240;

    protected $fillable = [
        'agency_id', 'rental_work_order_id', 'rental_fault_report_id', 'payer', 'tenant_percent', 'owner_percent', 'reason', 'evidence_path',
        'evidence_name', 'found_text', 'cause_text', 'suggested_payer', 'ruled_by_user_id', 'ruled_at', 'superseded_at', 'superseded_by_id',
        'owner_told_at', 'tenant_told_at', 'created_at',
    ];

    protected $casts = ['ruled_at' => 'datetime', 'superseded_at' => 'datetime', 'owner_told_at' => 'datetime', 'tenant_told_at' => 'datetime', 'created_at' => 'datetime'];

    public static function payerLabel(?string $payer): string
    {
        return self::PAYERS[$payer] ?? '';
    }

    /** The label for a fault-type row's default (null = "No default"). */
    public static function defaultLabel(?string $payer): string
    {
        return $payer ? (self::PAYERS[$payer] ?? 'No default') : 'No default';
    }

    /** "Owner" / "Tenant" / "Shared (tenant 40%, owner 60%)" - the agent's and the list's short form. */
    public function summary(): string
    {
        if ($this->payer === self::PAYER_SHARED) {
            return 'Shared (tenant ' . (int) $this->tenant_percent . '%, owner ' . (int) $this->owner_percent . '%)';
        }

        return self::payerLabel($this->payer);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id')->withoutGlobalScopes();
    }

    public function ruledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ruled_by_user_id');
    }
}
