<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class ProspectingClaim extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'prospecting_listing_id',
        'user_id',
        'status',
        'notes',
        'claimed_at',
        'feedback_at',
        'last_updated_at',
        'released_at',
        'flagged_at',
        'is_active',
    ];

    protected $casts = [
        'claimed_at'      => 'datetime',
        'feedback_at'     => 'datetime',
        'last_updated_at' => 'datetime',
        'released_at'     => 'datetime',
        'flagged_at'      => 'datetime',
        'is_active'       => 'boolean',
    ];

    public function listing()
    {
        return $this->belongsTo(ProspectingListing::class, 'prospecting_listing_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function hasReceivedFeedback(): bool
    {
        return $this->feedback_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->is_active
            && !$this->feedback_at
            && $this->claimed_at < now()->subHours(48);
    }

    public function needsReminder(): bool
    {
        return $this->is_active
            && $this->feedback_at
            && in_array($this->status, ['contacted', 'meeting_set'])
            && $this->last_updated_at < now()->subDays(7);
    }

    public function needsBmFlag(): bool
    {
        return $this->is_active
            && $this->status === 'listing'
            && $this->feedback_at
            && $this->last_updated_at < now()->subDays(14)
            && !$this->flagged_at;
    }
}
