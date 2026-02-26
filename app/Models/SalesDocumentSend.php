<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDocumentSend extends Model
{
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
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('sent_by', $user->id);
    }
}
