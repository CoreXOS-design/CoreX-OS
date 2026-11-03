<?php

namespace App\Models\Campaigns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-534 — one prospect (.ai/specs/campaigns.md §3.2, statuses §3.7). */
class CampaignLead extends Model
{
    use SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_REPLIED = 'replied';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUSES = ['new', 'queued', 'sent', 'replied', 'stopped', 'bounced', 'suppressed'];

    /** Statuses the send job never overwrites and never sends to. */
    public const TERMINAL = ['replied', 'stopped', 'bounced', 'suppressed'];

    protected $fillable = [
        'campaign_list_id', 'first_name', 'last_name', 'company', 'town', 'email', 'phone', 'notes',
        'status', 'status_reason', 'status_changed_at', 'created_by', 'updated_by',
    ];

    protected $casts = ['status_changed_at' => 'datetime'];

    public function list(): BelongsTo
    {
        return $this->belongsTo(CampaignList::class, 'campaign_list_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /** Move to $status unless already terminal (a terminal status is never overwritten by anything but a deliberate reset). */
    public function moveTo(string $status, ?string $reason = null): void
    {
        if ($this->isTerminal() || $this->status === $status) {
            return;
        }
        $this->forceFill(['status' => $status, 'status_reason' => $reason, 'status_changed_at' => now()])->save();
    }

    /** Set a terminal status (stop / bounce / reply / suppress). The first terminal status wins. */
    public function terminate(string $status, string $reason): void
    {
        if ($this->isTerminal()) {
            return;
        }
        $this->forceFill(['status' => $status, 'status_reason' => $reason, 'status_changed_at' => now()])->save();
    }

    public static function normaliseEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
