<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * DR2 Wave 2 — per-agency "Deal → Property → Portal status sync" settings.
 * One row per agency (agency_id UNIQUE). All OFF by default except the decline-revert
 * safety companion. Mirrors the AgencyContactSettings singleton pattern.
 */
class AgencyDealSyncSettings extends Model
{
    use BelongsToAgency;

    protected $table = 'agency_deal_sync_settings';

    protected $fillable = [
        'agency_id',
        'flag_property_under_offer_on_deal',
        'sold_milestone',
        'revert_property_on_deal_declined',
    ];

    protected $casts = [
        'flag_property_under_offer_on_deal' => 'boolean',
        'revert_property_on_deal_declined' => 'boolean',
    ];

    protected $attributes = [
        'flag_property_under_offer_on_deal' => false,
        'sold_milestone' => null,
        'revert_property_on_deal_declined' => true,
    ];

    /** One row per agency; created with conservative defaults on first access. */
    public static function forAgency(int $agencyId): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agencyId],
            [
                'flag_property_under_offer_on_deal' => false,
                'sold_milestone' => null,
                'revert_property_on_deal_declined' => true,
            ]
        );
    }

    /** The accepted_status stage ('G'/'R') at which SOLD flags, or null when OFF. */
    public function soldMilestoneStage(): ?string
    {
        return match ($this->sold_milestone) {
            'granted' => 'G',
            'registered' => 'R',
            default => null,
        };
    }
}
