<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSponsorship extends Model
{
    protected $fillable = [
        'agent_user_id',
        'sponsor_user_id',
        'sponsored_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'sponsored_at' => 'date',
        'is_active' => 'boolean',
    ];

    // ── Relationships ──

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Methods ──

    /**
     * Get all Tier 1 agents (directly sponsored by a given user).
     */
    public static function getTier1Agents(int $sponsorUserId)
    {
        return static::active()
            ->where('sponsor_user_id', $sponsorUserId)
            ->with('agent')
            ->get();
    }

    /**
     * Walk the sponsorship tree upward from a producing agent.
     * Returns an array of [user_id => tier] for up to $maxDepth levels.
     */
    public static function getSponsorChain(int $agentUserId, int $maxDepth = 7): array
    {
        $chain = [];
        $currentId = $agentUserId;

        for ($tier = 1; $tier <= $maxDepth; $tier++) {
            $sponsorship = static::active()
                ->where('agent_user_id', $currentId)
                ->first();

            if (!$sponsorship) {
                break;
            }

            $chain[$sponsorship->sponsor_user_id] = $tier;
            $currentId = $sponsorship->sponsor_user_id;
        }

        return $chain;
    }

    /**
     * Get the full downline tree for a sponsor (recursive, breadth-first).
     */
    public static function getFullTree(int $sponsorUserId, int $maxDepth = 7): array
    {
        $tree = [];
        $currentLevel = [$sponsorUserId];

        for ($tier = 1; $tier <= $maxDepth; $tier++) {
            $nextLevel = static::active()
                ->whereIn('sponsor_user_id', $currentLevel)
                ->pluck('agent_user_id')
                ->toArray();

            if (empty($nextLevel)) {
                break;
            }

            foreach ($nextLevel as $agentId) {
                $tree[$agentId] = $tier;
            }

            $currentLevel = $nextLevel;
        }

        return $tree;
    }

    /**
     * Count First Line Qualifying Agents (FLQAs) for a sponsor.
     * FLQA = Tier 1 agent with 2+ transactions OR R50,000+ GCI in last 6 months.
     */
    public static function getFLQACount(int $sponsorUserId): int
    {
        $tier1Ids = static::active()
            ->where('sponsor_user_id', $sponsorUserId)
            ->pluck('agent_user_id');

        if ($tier1Ids->isEmpty()) {
            return 0;
        }

        $sixMonthsAgo = now()->subMonths(6);

        return CommissionLedger::whereIn('user_id', $tier1Ids)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->whereIn('status', ['confirmed', 'paid'])
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= 2 OR SUM(gross_commission) >= 50000')
            ->select('user_id')
            ->get()
            ->count();
    }
}
