<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToAgency;
class RevenueShareLedger extends Model
{
    use BelongsToAgency;

    protected $table = 'revenue_share_ledger';

    protected $fillable = [
        'agency_id',
        'commission_ledger_id',
        'producing_agent_id',
        'receiving_agent_id',
        'tier',
        'company_dollar',
        'share_percent',
        'share_amount',
        'status',
        'period_month',
        'paid_at',
    ];

    protected $casts = [
        'company_dollar' => 'decimal:2',
        'share_percent' => 'decimal:2',
        'share_amount' => 'decimal:2',
        'period_month' => 'date',
        'paid_at' => 'datetime',
    ];

    // ── Relationships ──

    public function commissionEntry()
    {
        return $this->belongsTo(CommissionLedger::class, 'commission_ledger_id');
    }

    public function producingAgent()
    {
        return $this->belongsTo(User::class, 'producing_agent_id');
    }

    public function receivingAgent()
    {
        return $this->belongsTo(User::class, 'receiving_agent_id');
    }

    // ── Scopes ──

    public function scopeForAgent($query, int $userId)
    {
        return $query->where('receiving_agent_id', $userId);
    }

    public function scopeForMonth($query, $date)
    {
        $month = \Carbon\Carbon::parse($date)->startOfMonth()->toDateString();

        return $query->where('period_month', $month);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'calculated');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }
}
