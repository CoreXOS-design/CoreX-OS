<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RmcpVersion extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'version_number',
        'title',
        'status',
        'approved_by',
        'approved_at',
        'approver_title',
        'board_approval_document_path',
        'approval_ip',
        'approval_notes',
        'effective_from',
        'superseded_at',
        'superseded_by_version_id',
        'next_review_due',
        'change_notes',
        'created_by',
    ];

    protected $casts = [
        'approved_at'    => 'datetime',
        'superseded_at'  => 'datetime',
        'effective_from' => 'date',
        'next_review_due' => 'date',
    ];

    // ── Relationships ──

    public function sections(): HasMany
    {
        return $this->hasMany(RmcpSection::class, 'rmcp_version_id')->orderBy('display_order');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_version_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    // ── Methods ──

    public function canBeEdited(): bool
    {
        return $this->status === 'draft';
    }

    public function approve(User $user, string $title, ?string $documentPath, ?string $notes): void
    {
        // Supersede the current active version for this agency
        self::where('agency_id', $this->agency_id)
            ->where('status', 'active')
            ->update([
                'status'                  => 'superseded',
                'superseded_at'           => now(),
                'superseded_by_version_id' => $this->id,
            ]);

        $this->update([
            'status'                       => 'active',
            'approved_by'                  => $user->id,
            'approved_at'                  => now(),
            'approver_title'               => $title,
            'board_approval_document_path' => $documentPath,
            'approval_ip'                  => request()->ip(),
            'approval_notes'               => $notes,
        ]);
    }
}
