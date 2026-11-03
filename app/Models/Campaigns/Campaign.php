<?php

namespace App\Models\Campaigns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-534 — a campaign (.ai/specs/campaigns.md §3.3). */
class Campaign extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';

    public const STATUSES = ['draft', 'running', 'paused', 'done'];

    public const MAX_STEPS = 3;

    protected $fillable = [
        'name', 'campaign_list_id', 'campaign_sender_id', 'status', 'send_days', 'send_from', 'send_to', 'daily_limit',
        'started_at', 'done_at', 'paused_reason', 'last_run_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'send_days' => 'array',
        'daily_limit' => 'integer',
        'started_at' => 'datetime',
        'done_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(CampaignList::class, 'campaign_list_id')->withTrashed();
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(CampaignSender::class, 'campaign_sender_id')->withTrashed();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('step_number');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PAUSED], true) && ! $this->trashed();
    }

    /** Whether $at falls on a send day between send_from and send_to (app timezone). */
    public function insideWindow(CarbonInterface $at): bool
    {
        $days = array_map('intval', (array) $this->send_days);
        if (! in_array((int) $at->isoWeekday(), $days, true)) {
            return false;
        }
        $time = $at->format('H:i:s');

        return $time >= $this->timeOf($this->send_from) && $time < $this->timeOf($this->send_to);
    }

    /** The next moment the window opens at or after $from (null when the campaign has no send days). */
    public function nextWindowOpen(CarbonInterface $from): ?CarbonInterface
    {
        if ($this->insideWindow($from)) {
            return $from;
        }
        $days = array_map('intval', (array) $this->send_days);
        if ($days === []) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $this->timeOf($this->send_from)));
        for ($i = 0; $i <= 7; $i++) {
            $candidate = $from->copy()->addDays($i)->setTime($h, $m, 0);
            if ($candidate->greaterThanOrEqualTo($from) && in_array((int) $candidate->isoWeekday(), $days, true)) {
                return $candidate;
            }
        }

        return null;
    }

    public function sendDaysLabel(): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = array_map('intval', (array) $this->send_days);
        sort($days);

        return implode(' ', array_map(fn ($d) => $names[$d] ?? '', $days));
    }

    public function timeLabel(?string $time): string
    {
        return substr($this->timeOf($time), 0, 5);
    }

    private function timeOf(?string $time): string
    {
        $time = (string) $time;

        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
