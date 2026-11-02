<?php

namespace App\Console\Commands\Campaigns;

use App\Services\Campaigns\CampaignInboxChecker;
use Illuminate\Console\Command;

/** AT-534 — read each in-use sender's inbox for replies, STOP replies and bounces (.ai/specs/campaigns.md §6.6). */
class CheckCampaignInbox extends Command
{
    protected $signature = 'campaigns:check-inbox';

    protected $description = 'Check campaign sending addresses for replies, STOP replies and bounces';

    public function handle(CampaignInboxChecker $checker): int
    {
        foreach ($checker->checkAll() as $senderId => $outcome) {
            $this->line("Sender {$senderId}: {$outcome['checked']} message(s)" . ($outcome['error'] ? " — error: {$outcome['error']}" : ''));
        }

        return self::SUCCESS;
    }
}
