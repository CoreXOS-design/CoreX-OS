<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignatureRequest extends Model
{
    use SoftDeletes;

    protected $table = 'signature_requests';

    protected $fillable = [
        'signature_template_id',
        'party_role',
        'role_index',
        'signing_order',
        'signing_group',
        'signer_name',
        'signer_email',
        'signer_id_number',
        'token',
        'token_expires_at',
        'status',
        'sent_at',
        'viewed_at',
        'completed_at',
        'reminder_sent_at',
        'reminder_count',
        'ip_address',
        'user_agent',
        'sent_by',
        'message',
        'signing_method',
        'wet_ink_upload_path',
        'wet_ink_status',
        'wet_ink_rejection_note',
        'reviewed_by',
        'reviewed_at',
        'team_alerted_at',
        'authorised_by',
        'authorised_at',
        'fica_required',
        'contact_id',
        'fica_submission_id',
    ];

    protected $casts = [
        'role_index' => 'integer',
        // HD-5 — NULL is meaningful: "a group of one" (checkpoints on its own, today's behaviour).
        'signing_group' => 'integer',
        'token_expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'team_alerted_at' => 'datetime',
        'authorised_at' => 'datetime',
        'fica_required' => 'boolean',
    ];

    // Status constants
    const STATUS_WAITING = 'waiting';
    const STATUS_PENDING = 'pending';
    const STATUS_VIEWED = 'viewed';
    const STATUS_PARTIALLY_SIGNED = 'partially_signed';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_DECLINED = 'declined';
    const STATUS_DEFERRED = 'deferred';

    // Wet ink status constants
    const WET_INK_PENDING_UPLOAD = 'pending_upload';
    const WET_INK_UPLOADED_PENDING_REVIEW = 'uploaded_pending_review';
    const WET_INK_APPROVED = 'approved';
    const WET_INK_REJECTED = 'rejected';

    // --- Relationships ---

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function contact()
    {
        return $this->belongsTo(\App\Models\Contact::class);
    }

    public function ficaSubmission()
    {
        return $this->belongsTo(\App\Models\FicaSubmission::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function authoriser()
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function inspections()
    {
        return $this->hasMany(WetInkInspection::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function sectionAcceptances()
    {
        return $this->hasMany(SectionAcceptance::class);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_EXPIRED, self::STATUS_DECLINED, self::STATUS_COMPLETED]);
    }

    public function scopeNeedsReminder($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VIEWED, self::STATUS_PARTIALLY_SIGNED])
            ->whereNotNull('sent_at');
    }

    public function scopeExpirable($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VIEWED, self::STATUS_PARTIALLY_SIGNED])
            ->where('token_expires_at', '<', now());
    }

    // --- Helpers ---

    public function isExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isWetInk(): bool
    {
        return $this->signing_method === 'wet_ink';
    }

    public function isDeferred(): bool
    {
        return $this->status === self::STATUS_DEFERRED;
    }

    public function daysUntilExpiry(): int
    {
        if (!$this->token_expires_at) {
            return 0;
        }
        return max(0, (int) now()->diffInDays($this->token_expires_at, false));
    }

    public function daysSinceSent(): int
    {
        if (!$this->sent_at) {
            return 0;
        }
        return (int) $this->sent_at->diffInDays(now());
    }

    // ── Recipient Loop Engine B1 — indexed identity ──

    /**
     * Indexed identity token in `{party_role}_{role_index}` form. Used by
     * downstream renderer / signing view layers to address a specific
     * recipient instance distinct from siblings sharing the same role.
     *
     *   party_role=seller, role_index=2 → 'seller_2'
     *   party_role=agent,  role_index=1 → 'agent_1'
     *
     * Note: pre-B1 the suffixed form was stored ON party_role itself.
     * The B1 migration split it into the dedicated column; this accessor
     * reconstructs the legacy shape when callers need it for matching
     * against template metadata that still uses the suffixed form.
     */
    public function getRoleIdentityAttribute(): string
    {
        return $this->party_role . '_' . ((int) ($this->role_index ?? 1));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeForRoleInstance($query, string $roleToken, int $index)
    {
        return $query->where('party_role', $roleToken)->where('role_index', $index);
    }
}
