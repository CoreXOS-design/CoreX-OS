<?php

namespace App\Console\Commands;

use App\Models\Communications\CommunicationAttachment;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\WaMediaRecoveryService;
use Illuminate\Console\Command;

/**
 * AT-148 — recover WhatsApp media attachments stuck on "processing"
 * (media_status pending/failed) by re-requesting them from WAHA and downloading.
 * One-off remediation for rows captured before the host-rewrite fix; also handy
 * operationally. Agency-scoped by --agency, or all agencies.
 */
class RecoverWaMedia extends Command
{
    protected $signature = 'communications:recover-wa-media
        {--agency= : limit to one agency id}
        {--id=* : specific communication_attachments id(s)}
        {--include-failed : also retry rows already marked failed}
        {--dry-run : list what would be retried, change nothing}';

    protected $description = 'Re-fetch WhatsApp media attachments stuck pending/failed (AT-148)';

    public function handle(WaMediaRecoveryService $recovery): int
    {
        $q = CommunicationAttachment::withoutGlobalScope(AgencyScope::class)
            ->whereIn('media_status', $this->option('include-failed')
                ? [CommunicationAttachment::MEDIA_PENDING, CommunicationAttachment::MEDIA_FAILED]
                : [CommunicationAttachment::MEDIA_PENDING]);

        if ($agency = $this->option('agency')) {
            $q->where('agency_id', (int) $agency);
        }
        if ($ids = array_filter((array) $this->option('id'))) {
            $q->whereIn('id', $ids);
        }

        $rows = $q->orderBy('id')->get();
        $this->info("Found {$rows->count()} stuck media attachment(s).");

        if ($this->option('dry-run')) {
            $rows->each(fn ($a) => $this->line("  #{$a->id} comm={$a->communication_id} status={$a->media_status} mime={$a->mime} ref=" . mb_substr((string) $a->remote_ref, 0, 70)));
            return self::SUCCESS;
        }

        $ok = 0;
        foreach ($rows as $att) {
            // Reset a failed row to pending so recover() will actually try it.
            if ($att->media_status === CommunicationAttachment::MEDIA_FAILED) {
                $att->forceFill(['media_status' => CommunicationAttachment::MEDIA_PENDING])->save();
            }
            $done = $recovery->recover($att->refresh());
            $this->line(($done ? '  <info>OK</info>   ' : '  <error>FAIL</error> ') . "#{$att->id} → " . $att->refresh()->media_status);
            $ok += $done ? 1 : 0;
        }

        $this->info("Recovered {$ok}/{$rows->count()}.");

        return self::SUCCESS;
    }
}
