<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToAgency;

/**
 * 2026-08-15 (Johan, HFC tenant-isolation fix, Wave 2, #7) — added
 * BelongsToAgency. scopeVisibleTo()'s 'all' branch was fully unscoped —
 * any role with data-scope 'all' saw every agency's sent sales documents.
 * Fixed "for free" by the global scope, no code change needed in
 * scopeVisibleTo() itself.
 */
class SalesDocumentSend extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'document_id',
        'document_name',
        'original_file_path',
        'agency_id',
        'sent_by',
        'message',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    // ── Relationships ──

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(SalesDocumentRecipient::class)->orderBy('signing_order');
    }

    // ── Helpers ──

    public function currentRecipient(): ?SalesDocumentRecipient
    {
        return $this->recipients()
            ->whereNotIn('status', ['approved'])
            ->orderBy('signing_order')
            ->first();
    }

    public function isComplete(): bool
    {
        return $this->recipients()->where('status', '!=', 'approved')->count() === 0;
    }

    public function needsApproval(): bool
    {
        return $this->recipients()->where('status', 'returned_pending_approval')->exists();
    }

    // ── Scopes ──

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'sales_docs');

        if ($scope === 'all') return $query;

        if ($scope === 'branch') {
            return $query->whereIn('sent_by', function ($sub) use ($user) {
                $sub->select('id')
                    ->from('users')
                    ->where('branch_id', $user->effectiveBranchId());
            });
        }

        // AT-267 — an assistant's 'own' is their Assigned Agent's; everyone else: [$user->id].
        if ($scope === 'own') return $query->whereIn('sent_by', $user->dataIdentityIds());

        return $query->whereRaw('1 = 0');
    }
}
