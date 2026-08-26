<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's count of one engagement metric for one listing on one website.
 *
 * Written ONLY by WebsiteListingStatsIngestService, and only as an increment
 * (INSERT … ON DUPLICATE KEY UPDATE metric_count = metric_count + VALUES(...)),
 * never as an assignment — two batches covering the same day must sum.
 *
 * `metric` is an OPEN key set. The website can start sending a new metric
 * without a CoreX deploy; anything matching ^[a-z0-9_]{1,40}$ is stored.
 *
 * Spec: .ai/specs/website-listing-stats.md §3.3, §4.2
 */
class ListingWebsiteStat extends Model
{
    use BelongsToAgency;

    /** The metric keys the website sends today. NOT a whitelist — see class doc. */
    public const METRIC_IMPRESSION         = 'impression';
    public const METRIC_DETAIL_VIEW        = 'detail_view';
    public const METRIC_UNIQUE_DETAIL_VIEW = 'unique_detail_view';
    public const METRIC_GALLERY_OPEN       = 'gallery_open';
    public const METRIC_PHONE_CLICK        = 'phone_click';
    public const METRIC_EMAIL_CLICK        = 'email_click';
    public const METRIC_SHARE_CLICK        = 'share_click';
    public const METRIC_ENQUIRY            = 'enquiry';

    /** Contact intent — the three clicks the "Contact Clicks" tile adds up. */
    public const CONTACT_METRICS = [
        self::METRIC_PHONE_CLICK,
        self::METRIC_EMAIL_CLICK,
        self::METRIC_SHARE_CLICK,
    ];

    protected $fillable = [
        'agency_id',
        'site',
        'property_id',
        'stat_date',
        'metric',
        'metric_count',
    ];

    protected $casts = [
        'stat_date'    => 'date',
        'metric_count' => 'integer',
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
