<?php

namespace App\Services\Auctions;

use App\Mail\Auctions\AuctionBidderMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The "<auction> is now live" email to everyone who signed up on the agency's auction page (.ai/specs/auctions.md §19.7). Run every minute
 * from `auctions:run`. Each auction is claimed (start_notified_at) BEFORE anything is sent, so two overlapping runs can never send twice;
 * one that started more than GRACE_MINUTES ago is marked done without sending, so a job that was down - or this feature being switched on
 * later - never mass-mails about an old auction.
 */
class AuctionStartNotifier
{
    public const GRACE_MINUTES = 120;

    public function __construct(private SignedUpBuyers $buyers) {}

    /** @return int how many emails were queued */
    public function run(): int
    {
        $sent = 0;
        $due = Auction::withoutGlobalScopes()->whereIn('status', [Auction::STATUS_PUBLISHED, Auction::STATUS_OPEN])
            ->whereNull('start_notified_at')->whereNotNull('starts_at')->where('starts_at', '<=', now())->get();

        foreach ($due as $auction) {
            // claim it: only the run that flips NULL -> now() goes on
            $claimed = Auction::withoutGlobalScopes()->whereKey($auction->id)->whereNull('start_notified_at')->update(['start_notified_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $agencyId = (int) $auction->agency_id;
            if ($auction->starts_at->lt(now()->subMinutes(self::GRACE_MINUTES)) || ! AgencyAuctionSettings::startEmailsEnabledFor($agencyId)) {
                continue;
            }

            $sent += $this->send($auction);
        }

        return $sent;
    }

    private function send(Auction $auction): int
    {
        $agency = Agency::withoutGlobalScopes()->find($auction->agency_id);
        if (! $agency) {
            return 0;
        }
        $url = route('public.auctions.auction', [$agency->slug, $auction->id]);
        $facts = [
            'closes' => $auction->format === 'timed' && $auction->ends_at ? $auction->ends_at->format('j M Y, H:i') : null,
            'lots' => $auction->lots()->withoutGlobalScopes()->whereIn('status', ['published', 'open', 'sold', 'sold_subject_to_confirmation', 'passed_in'])->count(),
        ];

        $n = 0;
        foreach ($this->buyers->recipients((int) $auction->agency_id) as $person) {
            try {
                Mail::to($person->email)->queue(new AuctionBidderMail('started', $person->name ?: (string) strstr($person->email, '@', true), $auction, null, $url, $facts));
                $n++;
            } catch (\Throwable $e) {
                Log::warning('Auction start mail failed: ' . $e->getMessage(), ['auction' => $auction->id]);
            }
        }

        return $n;
    }
}
