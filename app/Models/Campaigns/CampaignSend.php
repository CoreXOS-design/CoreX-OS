<?php

namespace App\Models\Campaigns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-534 — one email to one lead for one step (.ai/specs/campaigns.md §3.5). UNIQUE (campaign_step_id, campaign_lead_id) is the
 * never-send-twice guarantee; rows are history and are never archived from the UI.
 */
class CampaignSend extends Model
{
    use SoftDeletes;

    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUSES = ['sending', 'sent', 'failed', 'unknown'];

    /** Statuses that count against the daily limit (a mail may have left). */
    public const COUNTS_AGAINST_LIMIT = ['sending', 'sent', 'unknown'];

    protected $fillable = [
        'campaign_id', 'campaign_step_id', 'campaign_lead_id', 'status', 'attempts', 'to_email', 'from_email', 'subject', 'body', 'body_html',
        'message_id', 'sent_at', 'error_reason', 'replied_at', 'bounced_at', 'unsubscribed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'replied_at' => 'datetime',
        'bounced_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withTrashed();
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'campaign_step_id')->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CampaignLead::class, 'campaign_lead_id')->withTrashed();
    }
}
