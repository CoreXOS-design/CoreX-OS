<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\BuyerIntelligenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecomputeBuyerRiskScores extends Command
{
    protected $signature = 'buyers:recompute-risk {--buyer= : Specific buyer contact ID}';
    protected $description = 'Recompute lost-risk scores for all buyers (or a specific one)';

    public function handle(): int
    {
        $service = app(BuyerIntelligenceService::class);

        $query = Contact::withoutGlobalScopes()->where('is_buyer', true)->whereNull('deleted_at');
        if ($id = $this->option('buyer')) {
            $query->where('id', (int) $id);
        }

        $buyers = $query->get();
        $this->info("Computing risk scores for {$buyers->count()} buyers...");

        foreach ($buyers as $buyer) {
            $result = $service->getLostRiskScore($buyer->id);
            DB::table('buyer_lost_risk_scores')->insert([
                'contact_id' => $buyer->id,
                'score' => $result['score'],
                'factors_breakdown' => json_encode($result['factors']),
                'computed_at' => now(),
            ]);
        }

        $this->info('Done.');
        return 0;
    }
}
