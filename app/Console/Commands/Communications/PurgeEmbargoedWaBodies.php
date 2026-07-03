<?php

namespace App\Console\Commands\Communications;

use App\Models\Agency;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\AgentCaptureConsentService;
use App\Services\Communications\CommunicationStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * AT-168 Part B — the embargo purge (the POPIA safety valve).
 *
 * An embargoed WhatsApp body that is never consented to must not linger forever.
 * After each agency's configurable retention window (agencies.wa_embargo_retention_days,
 * default 30) this GENUINELY removes the body content of still-embargoed,
 * still-not-opted-in messages: body_text/preview nulled, the raw bytes deleted
 * (dedup-safe — only when no other row references the file), body_status set to
 * 'embargo_purged'. This is the deliberate, documented exception to CoreX's
 * no-hard-delete doctrine — it operates at the BODY level; the FICA envelope
 * (identity, timestamp, thread, links) is retained, the message row is never
 * deleted. Consented bodies (opted-in) are skipped — they belong to the agent.
 *
 * Scheduled daily; agency-scopable and --dry-run safe.
 */
class PurgeEmbargoedWaBodies extends Command
{
    protected $signature = 'communications:purge-embargoed-bodies
        {--agency= : Restrict to one agency id (default: all agencies)}
        {--dry-run : Report what would be purged without writing}';

    protected $description = 'Purge embargoed WhatsApp bodies past their agency retention window when consent was never granted (POPIA).';

    public function handle(CommunicationStorageService $storage, AgentCaptureConsentService $consent): int
    {
        $agencyId = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $dryRun   = (bool) $this->option('dry-run');
        $scope    = $agencyId !== null ? "agency {$agencyId}" : 'all agencies';

        $agencies = Agency::query()
            ->when($agencyId !== null, fn ($q) => $q->where('id', $agencyId))
            ->get(['id', 'wa_embargo_retention_days']);

        $purged = 0;
        $wouldPurge = 0;

        foreach ($agencies as $agency) {
            $days   = (int) ($agency->wa_embargo_retention_days ?: 30);
            $cutoff = now()->subDays($days);

            $rows = Communication::query()->withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agency->id)
                ->where('channel', Communication::CHANNEL_WHATSAPP)
                ->where('body_status', 'embargoed')
                ->whereNull('purged_at')
                ->where('occurred_at', '<', $cutoff)
                ->get();

            foreach ($rows as $comm) {
                // Skip a body the owning agent HAS since consented to — it is theirs
                // to keep (release, not purge, is the right action there).
                $contactId = (int) CommunicationLink::query()->withoutGlobalScope(AgencyScope::class)
                    ->where('communication_id', $comm->id)
                    ->where('linkable_type', Contact::class)
                    ->value('linkable_id');
                if ($contactId > 0 && $consent->isCaptureOptedIn((int) $comm->owner_user_id, $contactId)) {
                    continue;
                }

                if ($dryRun) {
                    $wouldPurge++;
                    continue;
                }

                $rawPath = $comm->raw_path;

                $comm->forceFill([
                    'body_text'    => null,
                    'body_preview' => null,
                    'body_status'  => 'embargo_purged',
                    'text_hash'    => null,
                    'raw_path'     => null,
                    'content_hash' => null,
                    // AT-163 — a transcript is body content; it follows the body on
                    // purge (an embargoed note is never transcribed, so this is
                    // defensive — but the transcript never outlives the body).
                    'transcript_text'    => null,
                    'transcript_preview' => null,
                    'transcript_status'  => null,
                ])->save();

                // Delete the raw bytes only if no other row still references them
                // (content-addressed dedup safety).
                if ($rawPath) {
                    $stillReferenced = Communication::query()->withoutGlobalScope(AgencyScope::class)
                        ->where('raw_path', $rawPath)->exists();
                    if (! $stillReferenced) {
                        $storage->delete($rawPath);
                    }
                }

                $purged++;
            }
        }

        if ($dryRun) {
            $this->warn("[dry-run] Would purge {$wouldPurge} expired embargoed WhatsApp body(ies) ({$scope}). Nothing written.");
            return self::SUCCESS;
        }

        Log::info('AT-168 embargoed WhatsApp bodies purged (POPIA retention)', [
            'scope' => $scope, 'agency_id' => $agencyId, 'purged' => $purged,
        ]);

        $this->info("Purged {$purged} expired embargoed WhatsApp body(ies) ({$scope}). Envelopes retained.");

        return self::SUCCESS;
    }
}
