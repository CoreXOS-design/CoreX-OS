<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-132 Wave 1 — per-thread settings (one source of truth per (agency, contact,
 * thread_key)). Wave 1 carries the owner's "hide subject" privacy toggle for a
 * sensitive-subject thread; future per-thread settings (pin/mute/retention) land
 * here too. A thread is a GROUP of communications rows sharing a thread_key, not a
 * row of its own — so this is where a per-thread flag belongs.
 *
 * BelongsToAgency (AgencyScope) + SoftDeletes. Absence of a row = default (subject
 * shown). Nothing reads this in Step 1; the gate/list wiring lands in later steps.
 *
 * Spec: .ai/specs/at132-perthread-comms-gate.md §3.3
 */
class CommsThreadSetting extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id', 'contact_id', 'thread_key', 'hide_subject', 'set_by_user_id',
    ];

    protected $casts = [
        'hide_subject' => 'boolean',
    ];

    // ── Relationships ──

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    // ── Scopes ──

    public function scopeForContact($query, int $contactId)
    {
        return $query->where('contact_id', $contactId);
    }

    public function scopeForThread($query, string $threadKey)
    {
        return $query->where('thread_key', $threadKey);
    }
}
