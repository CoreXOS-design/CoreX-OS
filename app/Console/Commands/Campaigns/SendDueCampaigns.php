<?php

namespace App\Console\Commands\Campaigns;

use App\Jobs\Campaigns\SendCampaignBatchJob;
use App\Services\Campaigns\CampaignImportService;
use App\Services\Campaigns\CampaignSendService;
use Illuminate\Console\Command;

/** AT-534 — queue one send run per running campaign (.ai/specs/campaigns.md §6.1). Scheduled every five minutes. */
class SendDueCampaigns extends Command
{
    protected $signature = 'campaigns:send-due';

    protected $description = 'Queue a send run for every running campaign';

    public function handle(CampaignSendService $sends, CampaignImportService $imports): int
    {
        $sends->markStaleSendingUnknown();
        $imports->pruneStaleUploads(); // abandoned import files hold prospect personal data — never left lying around
        $count = 0;
        foreach ($sends->runnable() as $campaign) {
            SendCampaignBatchJob::dispatch($campaign->id);
            $count++;
        }
        $this->info("Queued {$count} campaign run(s).");

        return self::SUCCESS;
    }
}
