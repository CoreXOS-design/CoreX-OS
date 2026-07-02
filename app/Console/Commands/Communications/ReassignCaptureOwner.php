<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\CommsAccessAuditLog;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AT-153 — re-own WhatsApp capture threads that were wrongly stamped with a
 * platform/owner (null-agency) owner_user_id back to the real agency agent.
 *
 * Capture stamps owner_user_id = the capture device's user_id. When a device was
 * registered under the platform super-admin (agency_id NULL), every captured
 * message got a null-agency owner, which the access-request flow could neither
 * name nor route/authorise within the agency (see
 * .ai/audits/2026-07-02-comms-access-request-flow-broken.md). This reassigns
 * those rows to a real agency agent, AUDITED (EVENT_OWNERSHIP_TRANSFER per
 * thread). No hard deletes — only owner_user_id changes; bodies/links untouched.
 *
 * Guarded: --to must be an active AGENCY agent (has an agency, not an owner-role
 * account), and only comms in that agent's agency are reassigned (never crosses
 * tenancy).
 */
class ReassignCaptureOwner extends Command
{
    protected $signature = 'communications:reassign-capture-owner
        {--from= : the wrong owner user id (e.g. the platform super-admin)}
        {--to= : the correct agency agent user id to own the threads}
        {--dry-run : report what would change without writing}';

    protected $description = 'Re-own WhatsApp capture threads from a platform/wrong owner to a real agency agent (audited).';

    public function handle(): int
    {
        $fromId = (int) $this->option('from');
        $toId   = (int) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');

        if ($fromId <= 0 || $toId <= 0) {
            $this->error('Both --from and --to are required (user ids).');
            return self::FAILURE;
        }

        $to = User::withoutGlobalScope(AgencyScope::class)->find($toId);
        if (! $to) {
            $this->error("--to user {$toId} not found.");
            return self::FAILURE;
        }
        $toAgency = $to->effectiveAgencyId();
        if (! $toAgency || $to->isOwnerRole()) {
            $this->error("--to user {$toId} must be a real agency agent (has an agency, not a platform/owner account).");
            return self::FAILURE;
        }

        // WhatsApp capture rows owned by --from, within --to's agency only.
        $base = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('channel', Communication::CHANNEL_WHATSAPP)
            ->whereNull('purged_at')
            ->where('owner_user_id', $fromId)
            ->where('agency_id', $toAgency);

        $count = (clone $base)->count();
        if ($count === 0) {
            $this->info("No WhatsApp capture rows owned by user {$fromId} in agency {$toAgency}.");
            return self::SUCCESS;
        }

        $threadKeys = (clone $base)->distinct()->pluck('thread_key');

        if ($dryRun) {
            $this->warn("[dry-run] Would re-own {$count} message(s) across {$threadKeys->count()} thread(s) from user {$fromId} → {$toId} (agency {$toAgency}). Nothing written.");
            return self::SUCCESS;
        }

        DB::transaction(function () use ($base, $threadKeys, $fromId, $toId, $toAgency) {
            // Audit one ownership_transfer per thread (with a representative linked
            // contact) BEFORE the update, so the trail names each moved thread.
            foreach ($threadKeys as $tk) {
                $rep = (clone $base)->when($tk === null,
                    fn ($q) => $q->whereNull('thread_key'),
                    fn ($q) => $q->where('thread_key', $tk))->first();
                $contactId = $rep
                    ? DB::table('communication_links')
                        ->where('communication_id', $rep->id)
                        ->where('linkable_type', Contact::class)
                        ->value('linkable_id')
                    : null;

                CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_OWNERSHIP_TRANSFER, [
                    'agency_id'       => $toAgency,
                    'actor_user_id'   => null, // console remediation
                    'subject_user_id' => $toId,
                    'contact_id'      => $contactId,
                    'detail'          => [
                        'reason'        => 'reassign_platform_capture_owner',
                        'from_user_id'  => $fromId,
                        'to_user_id'    => $toId,
                        'thread_key'    => $tk,
                    ],
                ]);
            }

            $updated = (clone $base)->update(['owner_user_id' => $toId]);

            Log::info('AT-153 reassigned WhatsApp capture ownership', [
                'from_user_id' => $fromId,
                'to_user_id'   => $toId,
                'agency_id'    => $toAgency,
                'messages'     => $updated,
                'threads'      => $threadKeys->count(),
            ]);
        });

        $this->info("Re-owned {$count} message(s) across {$threadKeys->count()} thread(s) from user {$fromId} → {$toId} (agency {$toAgency}). Audited (ownership_transfer).");

        return self::SUCCESS;
    }
}
