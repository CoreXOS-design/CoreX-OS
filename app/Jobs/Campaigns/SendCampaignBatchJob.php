<?php

namespace App\Jobs\Campaigns;

use App\Models\Campaigns\Campaign;
use App\Services\Campaigns\CampaignSendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AT-534 — one send run for one campaign (.ai/specs/campaigns.md §6.1). Unique per campaign so two runs never overlap; even if they
 * did, the claim-before-send index makes a double send impossible. On the `default` queue — the one every environment's workers drain.
 */
class SendCampaignBatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $campaignId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function handle(CampaignSendService $sends): void
    {
        $campaign = Campaign::find($this->campaignId);
        if ($campaign) {
            $sends->runBatch($campaign);
        }
    }
}
