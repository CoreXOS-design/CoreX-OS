<?php

namespace App\Models\Proforma;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-agency proforma numbering + issuing settings. Letterhead/logo/VAT-no/
 * vat_registered are read live off the Agency — NOT duplicated here.
 */
class AgencyProformaSettings extends Model
{
    use BelongsToAgency;

    protected $table = 'agency_proforma_settings';

    protected $fillable = [
        'agency_id', 'number_prefix', 'next_number', 'number_padding',
        'due_date_rule', 'due_days', 'bank_details',
    ];

    protected $casts = [
        'next_number'    => 'integer',
        'number_padding' => 'integer',
        'due_days'       => 'integer',
    ];

    /** Sensible default per agency, created on first read (never null). */
    public static function forAgency(int $agencyId): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agencyId],
            [
                'number_prefix'  => 'PRO-',
                'next_number'    => 1,
                'number_padding' => 4,
                'due_date_rule'  => 'end_of_month',
                'due_days'       => 30,
            ]
        );
    }

    /** Format an integer sequence into the agency's display number (PRO-0001). */
    public function formatNumber(int $sequence): string
    {
        return $this->number_prefix . str_pad((string) $sequence, $this->number_padding, '0', STR_PAD_LEFT);
    }

    /** Resolve the due date for an invoice issued on $issuedOn per this agency's rule. */
    public function resolveDueDate(Carbon $issuedOn): Carbon
    {
        return match ($this->due_date_rule) {
            'days_after' => $issuedOn->copy()->addDays(max(0, (int) $this->due_days)),
            'on_receipt' => $issuedOn->copy(),
            default      => $issuedOn->copy()->endOfMonth(),   // end_of_month
        };
    }
}
