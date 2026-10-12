<?php

namespace App\Console\Commands\Auctions;

use App\Mail\Auctions\AuctionReminderMail;
use App\Models\Auction;
use App\Models\PortalLead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * AT-432 addendum — .ai/specs/auctions-advertising-mode.md §7. Deadline-driven
 * reminders to everyone who enquired about a lot in an auction: one when
 * registration is about to close, one the day before the sale. Each reminder
 * is recorded on the lead (lead_source_raw.auction_reminders_sent) so a
 * re-run — the scheduler fires hourly — never sends the same one twice.
 */
class SendAuctionReminders extends Command
{
    protected $signature = 'auctions:send-reminders';
    protected $description = 'Queue "registration closing" and "auction tomorrow" reminders to people who enquired about an auction lot.';

    public function handle(): int
    {
        $sent = 0;
        $horizon = now()->addDay();

        $auctions = Auction::queryWithoutAgencyScope()
            ->whereNotNull('catalogue_published_at')
            ->whereNotIn('status', [Auction::STATUS_CLOSED, Auction::STATUS_SETTLED, Auction::STATUS_CANCELLED, Auction::STATUS_POSTPONED])
            ->where('starts_at', '>', now())
            ->get();

        foreach ($auctions as $auction) {
            $due = [];
            if ($auction->registration_closes_at && $auction->registration_closes_at->between(now(), $horizon)) {
                $due[] = 'registration_closes';
            }
            if ($auction->starts_at->lte($horizon)) {
                $due[] = 'day_before';
            }
            if (! $due) {
                continue;
            }

            $leads = PortalLead::withoutGlobalScopes()
                ->where('agency_id', $auction->agency_id)
                ->where('portal', PortalLead::PORTAL_WEBSITE)
                ->whereNotNull('email')
                ->where('lead_source_raw->source', 'like', 'auction_page:'.$auction->id.':%')
                ->get();

            foreach ($leads as $lead) {
                $raw = $lead->lead_source_raw ?? [];
                $already = $raw['auction_reminders_sent'] ?? [];
                foreach ($due as $kind) {
                    if (in_array($kind, $already, true)) {
                        continue;
                    }
                    Mail::to($lead->email)->queue(new AuctionReminderMail($auction, $kind, $lead->name ?: 'there'));
                    $already[] = $kind;
                    $sent++;
                }
                $raw['auction_reminders_sent'] = $already;
                $lead->lead_source_raw = $raw;
                $lead->save();
            }
        }

        $this->info($sent.' reminder(s) queued.');

        return self::SUCCESS;
    }
}
