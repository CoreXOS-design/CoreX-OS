<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SignatureTemplate extends Model
{
    protected $table = 'signature_templates';

    protected $fillable = [
        'document_id',
        'document_hash',
        'status',
        'parties_json',
        'signing_order_json',
        'cosign_mode',
        'created_by',
        'completed_at',
        'rejected_at',
        'rejection_reason',
        'rejected_by',
        'signed_pdf_path',
        'signed_pdf_client_path',
        'flattened_pages_json',
    ];

    protected $casts = [
        'parties_json' => 'array',
        'signing_order_json' => 'array',
        'flattened_pages_json' => 'array',
        'completed_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_READY = 'ready';
    const STATUS_SIGNING = 'signing';
    const STATUS_AWAITING_TENANT = 'awaiting_tenant';
    const STATUS_AWAITING_LANDLORD = 'awaiting_landlord';
    const STATUS_AWAITING_COSIGNER = 'awaiting_cosigner';
    const STATUS_PENDING_AGENT_APPROVAL = 'pending_agent_approval';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_DECLINED = 'declined';
    const STATUS_REJECTED = 'rejected';

    // --- Relationships ---

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function markers()
    {
        return $this->hasMany(SignatureMarker::class);
    }

    public function requests()
    {
        return $this->hasMany(SignatureRequest::class);
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
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isBranchManager()) {
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
            self::STATUS_AWAITING_COSIGNER,
            self::STATUS_AWAITING_TENANT,
            self::STATUS_AWAITING_LANDLORD,
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

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function currentPartyRole(): ?string
    {
        $statusMap = [
            'signing' => 'agent',
            'awaiting_cosigner' => 'cosigner',
            'awaiting_tenant' => 'tenant',
            'awaiting_landlord' => 'landlord',
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
