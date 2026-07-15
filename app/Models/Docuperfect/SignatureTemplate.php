<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignatureTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'signature_templates';

    protected $fillable = [
        'document_id',
        'document_hash',
        'status',
        'parties_json',
        'signing_order_json',
        'group_order_json',
        'created_by',
        'is_candidate_flow',
        'supervisor_user_id',
        'completed_at',
        'rejected_at',
        'rejection_reason',
        'rejected_by',
        'signed_pdf_path',
        'signed_pdf_client_path',
        'flattened_pages_json',
        'sections_json',
        'document_version',
        'other_conditions_text',
        'amendment_status',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'parties_json' => 'array',
        'signing_order_json' => 'array',
        'group_order_json' => 'array',
        'flattened_pages_json' => 'array',
        'sections_json' => 'array',
        'is_candidate_flow' => 'boolean',
        'completed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_READY = 'ready';
    const STATUS_SIGNING = 'signing';
    const STATUS_AWAITING_TENANT = 'awaiting_tenant';
    const STATUS_AWAITING_LANDLORD = 'awaiting_landlord';
    const STATUS_AWAITING_BUYER = 'awaiting_buyer';
    const STATUS_AWAITING_SELLER = 'awaiting_seller';
    const STATUS_AWAITING_SUPERVISOR = 'awaiting_supervisor';
    const STATUS_AWAITING_SUPERVISOR_FINAL = 'awaiting_supervisor_final';
    const STATUS_PENDING_AGENT_APPROVAL = 'pending_agent_approval';
    const STATUS_RETURNED_TO_CANDIDATE = 'returned_to_candidate';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_DECLINED = 'declined';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PARTIAL = 'partial';
    const STATUS_AWAITING_DEFERRED = 'awaiting_deferred';
    const STATUS_AMENDMENT_REVIEW = 'amendment_review';
    // ES-3: amendment_initialing — agent approved a change; parties must
    // initial only the changed regions (focused view, not full re-sign).
    const STATUS_AMENDMENT_INITIALING = 'amendment_initialing';
    const STATUS_CANCELLED = 'cancelled';

    // amendment_status (the secondary column, varchar(255)) carries finer
    // amendment-phase state.
    const AMENDMENT_STATUS_PENDING_REVIEW = 'pending_review';
    const AMENDMENT_STATUS_INITIALING     = 'amendment_initialing';
    const AMENDMENT_STATUS_RESOLVED       = 'resolved';
    const AMENDMENT_STATUS_REJECTED       = 'rejected';

    // --- Relationships ---

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supervisorUser()
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function markers()
    {
        return $this->hasMany(SignatureMarker::class);
    }

    public function zones()
    {
        return $this->hasMany(SignatureZone::class);
    }

    public function requests()
    {
        return $this->hasMany(SignatureRequest::class);
    }

    /**
     * HD-6 (§4) — the LOCKED group order for a mandate: the sellers sign as one group, then the agent.
     *
     * Joint sellers on one mandate are one group, so the agent is not asked to authorise the gap
     * between two people signing the same document for the same reason (HD-5). The agent is a group of
     * their own, which is what puts the checkpoint where doctrine wants it: `sellers → agent`.
     *
     * There is deliberately NO order here for an OTP. Doctrine §4 specifies one
     * (`purchasers → agent → sellers → agent`), but an OTP is an alienation document: it may not be
     * e-signed at all under ECTA §13(1) — `Template::isEsignBlocked()` refuses it, and HD-2 closed the
     * pack path that was letting one through. A group order for a ceremony that can never legally run
     * would be dead code that looks like a feature. It gets written the day the law changes.
     */
    public const GROUP_ORDER_MANDATE = [['seller'], ['agent']];

    /**
     * The group a party signs in for THIS ceremony, or null when the ceremony has no group plan.
     *
     * Null is the default and the safe answer: an ungrouped party is a group of one and reaches the
     * agent checkpoint on its own, exactly as every ceremony does today. Grouping only ever happens
     * because a ceremony deliberately said so.
     */
    public function groupFor(string $partyRole): ?int
    {
        $plan = $this->group_order_json;

        if (! is_array($plan) || empty($plan)) {
            return null;
        }

        $role = strtolower(trim($partyRole));

        foreach (array_values($plan) as $i => $group) {
            $members = array_map(
                static fn ($m) => strtolower(trim((string) $m)),
                is_array($group) ? $group : [$group],
            );

            if (in_array($role, $members, true)) {
                return $i + 1; // groups are 1-based; 0 would be indistinguishable from "no group"
            }
        }

        // A party the plan does not mention (a witness, a supervisor) is not forced into someone
        // else's group — it stands alone and checkpoints alone.
        return null;
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function auditLog()
    {
        return $this->hasMany(SignatureAuditLog::class);
    }

    public function leaseRecord()
    {
        return $this->hasOne(LeaseRecord::class);
    }

    public function amendments()
    {
        return $this->hasMany(DocumentAmendment::class);
    }

    // --- Scopes ---

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_EXPIRED, self::STATUS_DECLINED, self::STATUS_REJECTED]);
    }

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'documents');

        if ($scope === 'all') return $query;

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            return $query->whereHas('document', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        return $query->where('created_by', $user->id);
    }

    // --- Helpers ---

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSigning(): bool
    {
        return in_array($this->status, [
            self::STATUS_SIGNING,
            self::STATUS_AWAITING_TENANT,
            self::STATUS_AWAITING_LANDLORD,
            self::STATUS_AWAITING_BUYER,
            self::STATUS_AWAITING_SELLER,
            self::STATUS_PENDING_AGENT_APPROVAL,
        ]);
    }

    public function isPendingAgentApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_AGENT_APPROVAL;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    public function isAwaitingDeferred(): bool
    {
        return $this->status === self::STATUS_AWAITING_DEFERRED;
    }

    public function hasSections(): bool
    {
        return !empty($this->sections_json);
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function currentPartyRole(): ?string
    {
        $order = $this->signing_order_json ?? ['agent', 'tenant', 'landlord'];
        $statusMap = [
            'signing' => 'agent',
            'awaiting_tenant' => 'tenant',
            'awaiting_landlord' => 'landlord',
            'awaiting_buyer' => 'buyer',
            'awaiting_seller' => 'seller',
        ];

        return $statusMap[$this->status] ?? null;
    }

    /**
     * Get signing progress for each party.
     */
    public function partyProgress(): array
    {
        $this->loadMissing(['requests', 'markers', 'signatures']);
        $parties = $this->parties_json ?? [];
        $progress = [];

        foreach ($parties as $party) {
            $role = $party['role'];
            $request = $this->requests->firstWhere('party_role', $role);

            $partyMarkers = $this->markers->where('assigned_party', $role);
            $totalRequired = $partyMarkers->where('required', true)->count();
            $signedMarkerIds = $this->signatures
                ->whereIn('signature_marker_id', $partyMarkers->pluck('id'))
                ->pluck('signature_marker_id')
                ->unique();

            $progress[$role] = [
                'name' => $party['name'],
                'email' => $party['email'],
                'role' => $role,
                'total_markers' => $totalRequired,
                'signed_markers' => $signedMarkerIds->count(),
                'signature_count' => $partyMarkers->where('type', 'signature')->count(),
                'initial_count' => $partyMarkers->where('type', 'initial')->count(),
                'is_complete' => $request ? $request->isComplete() : false,
                'signing_method' => $request?->signing_method ?? 'electronic',
                'completed_at' => $request?->completed_at,
                'ip_address' => $request?->ip_address,
                'wet_ink_status' => $request?->wet_ink_status,
                'reviewed_by' => $request?->reviewed_by,
                'reviewed_at' => $request?->reviewed_at,
                'is_deferred' => $request?->isDeferred() ?? false,
            ];
        }

        return $progress;
    }

    /**
     * Alias for the audit log relationship (plural).
     */
    public function auditLogs()
    {
        return $this->hasMany(SignatureAuditLog::class);
    }
}
