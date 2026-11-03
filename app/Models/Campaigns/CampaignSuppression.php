<?php

namespace App\Models\Campaigns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-534 — an address no CoreX campaign may email (.ai/specs/campaigns.md §3.6). Archive = lifted, restore = suppressed again. */
class CampaignSuppression extends Model
{
    use SoftDeletes;

    public const REASONS = [
        'reply_stop' => 'Replied STOP',
        'unsubscribe' => 'Unsubscribed',
        'hard_bounce' => 'Bounced',
        'manual' => 'Added by hand',
    ];

    protected $fillable = ['email', 'reason', 'campaign_send_id', 'note', 'created_by', 'updated_by'];

    public function send(): BelongsTo
    {
        return $this->belongsTo(CampaignSend::class, 'campaign_send_id');
    }

    public static function isSuppressed(string $email): bool
    {
        return static::where('email', CampaignLead::normaliseEmail($email))->exists();
    }

    /**
     * Suppress $email (idempotent — an existing active suppression is left as it is, a lifted one is NOT silently restored: the
     * owner lifted it on purpose, so a fresh row is added instead).
     */
    public static function suppress(string $email, string $reason, ?int $sendId = null, ?string $note = null, ?int $userId = null): self
    {
        $email = CampaignLead::normaliseEmail($email);
        $existing = static::where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return static::create([
                'email' => $email, 'reason' => $reason, 'campaign_send_id' => $sendId, 'note' => $note,
                'created_by' => $userId, 'updated_by' => $userId,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // A parallel request suppressed it first — the outcome is the same.
            return static::where('email', $email)->firstOrFail();
        }
    }
}
