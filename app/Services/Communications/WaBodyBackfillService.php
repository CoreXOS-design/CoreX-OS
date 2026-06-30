<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Scopes\AgencyScope;

/**
 * AT-135 — drives the read-only WhatsApp body backfill. WhatsApp stores message
 * bodies encrypted-at-rest in IndexedDB, so the envelope is captured immediately
 * (AT-133) but the body lands only once the bubble is rendered. Messages archived
 * before rendering carry body_status='unreadable'. This service tells the capture
 * extension WHICH chats still have bodies to recover, so its idle/read-only sweep
 * opens only those (not every chat) and scrapes the missing text.
 *
 * FICA: business WhatsApp must be retained 5 years — body capture is a compliance
 * obligation, hence the backfill. Strictly read-only on the extension side.
 */
class WaBodyBackfillService
{
    /**
     * Distinct last-9 (SA core) numbers of this agency's contacts that have ≥1
     * WhatsApp message archived with body_status='unreadable'. The extension
     * matches a chat's resolved number (last-9) against this set. Only numbers
     * already visible in the agent's own WhatsApp are ever revealed (the agency
     * contact list never reaches the browser).
     */
    public function pendingBodyNumbers(int $agencyId): array
    {
        return Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('channel', Communication::CHANNEL_WHATSAPP)
            ->where('body_status', 'unreadable')
            ->whereNull('purged_at')
            ->pluck('from_identifier')
            ->map(fn ($v) => $this->last9((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Count of unreadable WA bodies for an agency (coverage visibility). */
    public function pendingBodyCount(int $agencyId): int
    {
        return Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('channel', Communication::CHANNEL_WHATSAPP)
            ->where('body_status', 'unreadable')
            ->whereNull('purged_at')
            ->count();
    }

    private function last9(string $s): ?string
    {
        $d = preg_replace('/\D/', '', $s);
        return strlen($d) >= 9 ? substr($d, -9) : null;
    }
}
