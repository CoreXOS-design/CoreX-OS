<?php

namespace App\Console\Commands;

use App\Models\AgencyContactSettings;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Services\SellerOutreach\MarketingConsentService;
use App\Services\SellerOutreach\TransactionStateService;
use Illuminate\Console\Command;

/**
 * AT-81 — age PENDING outreach contacts off into a no_response opt-out.
 *
 * A contact moves to PENDING when an outreach consent-request is sent
 * (outreach_permission_asked_at stamped). If the agency's no-response window
 * elapses with genuine silence, this command lapses them to a marketing
 * opt-out with kind=no_response — DISTINCT from an explicit decline, so a future
 * re-contact capability can re-approach the no-response pool (NCC-gated, not
 * built here).
 *
 * "Genuine silence" (mirrors the AT-81 spec) = pending past the window AND no
 * opt-in AND no opt-out AND the latest send's outcome still 'sent' AND no click
 * (first_clicked_at null) AND NOT in a live transaction. Any engagement clears
 * the pending marker at the moment it happens (opt-in/opt-out/click), so this
 * command only ever sees truly silent contacts — the guards are belt-and-braces.
 *
 * Candidate pre-filter is outreach_permission_asked_at (the PENDING marker), but
 * the lapse itself is DELIVERY-ANCHORED: a contact is only lapsed when its latest
 * send was actually delivered — email at sent_at, or a WhatsApp pitch whose linked
 * communication was confirmed sent (send_status='sent') — and the window is measured
 * from that delivery time. A composed-but-never-delivered pitch (WhatsApp click-to-
 * chat that the agent never confirmed) has no delivery evidence and is NEVER lapsed.
 * This is the fix for the HFC false "no_response" opt-out, where a bulk WhatsApp
 * pitch blast at import wrongly lapsed contacts who were never actually messaged.
 *
 * Mirrors buyers:recompute-states (RecomputeBuyerStates): candidate load,
 * service resolve, --dry-run. Scheduled in routes/console.php.
 */
class RecomputeOutreachNoResponse extends Command
{
    protected $signature = 'outreach:recompute-no-response
        {--dry-run : List the contacts that would lapse without applying}
        {--agency= : Limit to a single agency id}';

    protected $description = 'Lapse PENDING outreach contacts to a no_response opt-out after the agency no-response window';

    public function handle(MarketingConsentService $consent, TransactionStateService $transactions): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $agencyOpt = $this->option('agency');

        $query = Contact::withoutGlobalScopes()
            ->whereNotNull('outreach_permission_asked_at') // PENDING marker set
            ->whereNull('messaging_opt_out_at')            // not already opted out
            ->whereNull('messaging_opted_in_at')           // not confirmed-opted-in
            ->whereNull('deleted_at')
            ->whereNull('purged_at');

        if ($agencyOpt !== null && $agencyOpt !== '') {
            $query->where('agency_id', (int) $agencyOpt);
        }

        $candidates = $query->get();
        $this->info("Scanning {$candidates->count()} pending outreach contacts...");

        $windowByAgency = [];
        $lapsed = 0;
        $skipped = 0;
        $withinWindow = 0;

        foreach ($candidates as $contact) {
            $agencyId   = (int) $contact->agency_id;
            $windowDays = $windowByAgency[$agencyId]
                ??= AgencyContactSettings::forAgency($agencyId)->outreachNoResponseDays();

            // The latest send must still be a clean, unclicked 'sent'. A click /
            // manual outcome (replied/booked/etc.) signals engagement → not silence.
            $latestSend = SellerOutreachSend::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('contact_id', $contact->id)
                ->whereNull('deleted_at')
                ->latest('sent_at')
                ->first();

            if (!$latestSend
                || $latestSend->outcome !== SellerOutreachSend::OUTCOME_SENT
                || $latestSend->first_clicked_at !== null) {
                $skipped++;
                continue;
            }

            // BELT-AND-BRACES delivery gate (AT-81 false-opt-out fix). A no-response
            // lapse must be driven by a message that was ACTUALLY delivered, measured
            // from the delivery time — never from a merely-composed pitch:
            //  • EMAIL is system-sent → delivered at the send's sent_at.
            //  • WHATSAPP is click-to-chat → delivered ONLY when its linked
            //    communication was confirmed sent (send_status='sent'); outcome='sent'
            //    alone means "pitch composed", not delivered.
            // No delivery evidence ⇒ $deliveredAt stays null ⇒ NEVER auto-opt-out.
            $deliveredAt = null;
            if ($latestSend->channel === Communication::CHANNEL_EMAIL) {
                $deliveredAt = $latestSend->sent_at;
            } elseif ($latestSend->communication_id) {
                $comm = Communication::withoutGlobalScopes()->find($latestSend->communication_id);
                if ($comm && $comm->send_status === Communication::SEND_STATUS_SENT) {
                    $deliveredAt = $comm->send_status_set_at ?? $latestSend->sent_at;
                }
            }

            if ($deliveredAt === null) {
                // Nothing was ever actually delivered — cannot be "no response".
                $skipped++;
                continue;
            }

            // Still inside the window (measured from actual delivery) — leave pending.
            if ($deliveredAt->greaterThan(now()->subDays($windowDays))) {
                $withinWindow++;
                continue;
            }

            // Never lapse a contact in a live transaction (mirror the opt-out gate).
            if ($transactions->isInLiveTransaction($agencyId, $contact)) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $asked = $contact->outreach_permission_asked_at?->toDateString();
                $this->line("  [agency {$agencyId}] {$contact->full_name} (#{$contact->id}) — asked {$asked}, window {$windowDays}d → no_response");
                $lapsed++;
                continue;
            }

            // Lapse: marketing-only opt-out (transactional channels stay open) with
            // the no_response sub-state. optOutContact() clears the pending marker.
            $consent->optOutContact(
                contact:     $contact,
                reason:      "No response to outreach within {$windowDays} days",
                source:      'system:no_response',
                actorUserId: null,
                blockAll:    false,
                kind:        Contact::OPT_OUT_KIND_NO_RESPONSE,
            );

            // Reflect the lapse on the send outcome (the enum already has the value;
            // until now only an agent set it manually). System actor → no user id.
            $latestSend->forceFill([
                'outcome'        => SellerOutreachSend::OUTCOME_NO_RESPONSE,
                'outcome_note'   => "Auto-lapsed: no response within {$windowDays} days",
                'outcome_set_at' => now(),
            ])->save();

            $lapsed++;
        }

        $this->info("{$withinWindow} still within window; {$skipped} skipped (engaged / live transaction / no clean send).");
        $this->info("{$lapsed} contacts " . ($dryRun ? 'would lapse to no_response.' : 'lapsed to no_response.'));
        if ($dryRun) {
            $this->warn('DRY RUN — no changes made.');
        }

        return self::SUCCESS;
    }
}
