<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDocumentSend extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'document_id',
        'document_name',
        'original_file_path',
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

        if ($scope === 'own') return $query->where('sent_by', $user->id);

        return $query->whereRaw('1 = 0');
    }
}
