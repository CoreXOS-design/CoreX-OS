<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentMentor extends Model
{
    protected $fillable = [
        'mentee_user_id',
        'mentor_user_id',
        'assigned_at',
        'graduated_at',
        'transactions_completed',
        'transactions_required',
        'is_active',
    ];

    protected $casts = [
        'assigned_at' => 'date',
        'graduated_at' => 'date',
        'is_active' => 'boolean',
    ];

    // ── Relationships ──

    public function mentee()
    {
        return $this->belongsTo(User::class, 'mentee_user_id');
    }

    public function mentor()
    {
        return $this->belongsTo(User::class, 'mentor_user_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Methods ──

    /**
     * Record a completed transaction for the mentee.
     * Checks if they should graduate.
     */
    public function recordTransaction(): void
    {
        $this->transactions_completed++;

        if ($this->transactions_completed >= $this->transactions_required) {
            $this->checkGraduation();
        }

        $this->save();
    }

    /**
     * Graduate the mentee if they've completed required transactions.
     */
    public function checkGraduation(): bool
    {
        if ($this->transactions_completed >= $this->transactions_required) {
            $this->is_active = false;
            $this->graduated_at = now()->toDateString();
            $this->save();

            return true;
        }

        return false;
    }
}
