<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single day's portal engagement metrics for one property on one portal.
 * Populated by P24StatsService from the Property24 statistics API. Summed over
 * a window (default 30 days) by PropertyIntelligenceService::getPortalPerformance.
 * See .ai/specs/portal-metrics.md.
 */
class PropertyPortalMetric extends Model
{
    use HasFactory, SoftDeletes, BelongsToAgency;

    public const PORTAL_P24 = 'p24';
    public const PORTAL_PP  = 'pp';

    protected $fillable = [
        'agency_id',
        'property_id',
        'portal',
        'portal_listing_number',
        'metric_date',
        'view_count',
        'alert_count',
        'tel_leads',
        'sms_leads',
        'request_details_leads',
        'total_leads',
        'total_contact_leads',
        'price',
        'synced_at',
    ];

    protected $casts = [
        'metric_date'           => 'date',
        'view_count'            => 'integer',
        'alert_count'           => 'integer',
        'tel_leads'             => 'integer',
        'sms_leads'             => 'integer',
        'request_details_leads' => 'integer',
        'total_leads'           => 'integer',
        'total_contact_leads'   => 'integer',
        'price'                 => 'decimal:2',
        'synced_at'             => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
