<?php

namespace App\Services\LeadResponse;

use App\Events\Contact\ContactContactedByAgent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Records the FIRST genuine response to a portal enquiry — once, at the moment it happens (Johan,
 * 2026-10-07). "Last contacted" is overwritten, so first-contact history cannot be rebuilt from it; this
 * writes portal_leads.first_response_* and never overwrites them.
 *
 * One real contact answers every open enquiry of that contact that had ALREADY arrived: an action dated
 * before a lead arrived is ignored, and a later repeat enquiry starts a fresh clock. Leads received before
 * the feature existed (response_tracked = false) are never touched here — they stay "Not measured".
 */
class LeadResponseRecorder
{
    /** @return int number of enquiries answered by this contact */
    public function record(int $contactId, CarbonInterface $at, ?int $byUserId, string $channel): int
    {
        if (! in_array($channel, ContactContactedByAgent::CHANNELS, true)) {
            return 0;
        }

        return DB::table('portal_leads')
            ->where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->where('response_tracked', true)
            ->whereNull('first_response_at')
            ->where('received_at', '<=', $at)
            ->update([
                'first_response_at' => $at,
                'first_response_by_user_id' => $byUserId,
                'first_response_channel' => $channel,
                'updated_at' => now(),
            ]);
    }
}
