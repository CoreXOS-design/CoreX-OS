<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FaultReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type',
        'severity',
        'title',
        'message',
        'exception_class',
        'file',
        'line',
        'trace',
        'url',
        'method',
        'user_id',
        'user_agent',
        'ip_address',
        'request_data',
        'screenshot_path',
        'status',
        'notes',
        'resolved_by',
        'resolved_at',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'request_data' => 'array',
        'resolved_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'occurrence_count' => 'integer',
        'line' => 'integer',
    ];

    // ── Relationships ──

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['new', 'investigating']);
    }

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeRecent($query)
    {
        return $query->orderByDesc('last_seen_at');
    }

    // ── Methods ──

    public function incrementOccurrence(): void
    {
        $this->increment('occurrence_count');
        $this->update(['last_seen_at' => now()]);
    }
}
