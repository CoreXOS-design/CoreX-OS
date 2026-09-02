<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\PortalLead;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo portal-lead data — Property24 / Private Property / website enquiries
 * against listed properties. There is no `status` column on `portal_leads`
 * (confirmed by reading App\Models\PortalLead in full — only PORTAL_P24/PP/
 * WEBSITE/SHARED_LINK source constants exist); the only real lifecycle
 * signals are `notified_at` (has an agent seen it in the toast/poller) and
 * `contact_id`/`contact_exists` (has the enquirer been matched/converted to
 * a Contact). "new/unactioned" vs "contacted" vs "converted" are simulated
 * with those real columns rather than an invented status field:
 *   - new/unactioned: notified_at = null, contact_id = null
 *   - contacted:      notified_at = set (agent has seen it), contact_id = null
 *   - converted:      notified_at = set, contact_id = a real existing Buyer
 *                      contact, contact_exists = true
 *
 * Spread across several properties and agents, `received_at` timestamps
 * relative to now() (recent for new, days-old for contacted, weeks-old for
 * converted — mirrors how a real lead ages through a pipeline).
 *
 * IDEMPOTENT BY CONSTRUCTION: firstOrCreate keyed on
 * (agency_id, listing_portal_ref) — one lead per deterministic portal
 * reference, never duplicated on a re-run.
 */
class DemoPortalLeadsSeeder
{
    private const TARGET_TOTAL = 36;

    /** @return array{inserted:int, note?:string} */
    public function run(int $agencyId = 1): array
    {
        $properties = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->where('is_demo', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'agent_id', 'address', 'suburb']);

        if ($properties->isEmpty()) {
            return ['inserted' => 0, 'note' => 'Skipped — no demo properties present.'];
        }

        $buyerContacts = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->where('is_buyer', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(self::TARGET_TOTAL)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $already = PortalLead::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->count();
        $need = max(0, self::TARGET_TOTAL - $already);
        if ($need === 0) {
            $note = "Portal leads: {$already}/" . self::TARGET_TOTAL . ' already present — nothing to do.';
            return ['inserted' => 0, 'note' => $note];
        }

        $portals = [PortalLead::PORTAL_P24, PortalLead::PORTAL_P24, PortalLead::PORTAL_PP, PortalLead::PORTAL_WEBSITE];
        $leadTypes = ['Email', 'Phone', 'WhatsApp'];

        $inserted = 0;
        $i = $already;

        while ($inserted < $need) {
            $property = $properties[$i % $properties->count()];
            $portal = $portals[$i % count($portals)];
            $leadType = $leadTypes[$i % count($leadTypes)];
            $ref = 'DEMO-' . strtoupper($portal) . '-' . ($i + 1);

            // Bucket by position: ~40% new/unactioned, ~35% contacted, ~25% converted.
            $bucket = $i % 20;
            if ($bucket < 8) {
                $stage = 'new';
            } elseif ($bucket < 15) {
                $stage = 'contacted';
            } else {
                $stage = 'converted';
            }

            $leadName = DemoNames::name('portal-lead-' . $agencyId . '-' . $i);
            $isWhatsApp = $leadType === 'WhatsApp';

            $receivedAt = match ($stage) {
                'new'       => now()->subHours(1 + ($i % 48)),
                'contacted' => now()->subDays(2 + ($i % 10)),
                'converted' => now()->subDays(14 + ($i % 45)),
            };
            $notifiedAt = $stage === 'new' ? null : $receivedAt->copy()->addHours(1 + ($i % 6));

            $contactId = null;
            $contactExists = false;
            $existingContactAgentId = null;
            if ($stage === 'converted' && $buyerContacts->isNotEmpty()) {
                $matchedContact = $buyerContacts[$i % $buyerContacts->count()];
                $contactId = $matchedContact->id;
                $contactExists = true;
                $existingContactAgentId = $property->agent_id;
            }

            $lead = PortalLead::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'listing_portal_ref' => $ref],
                [
                    'portal'                    => $portal,
                    'lead_type'                 => $leadType,
                    'listing_id'                => $property->id,
                    'contact_id'                => $contactId,
                    'contact_exists'            => $contactExists,
                    'existing_contact_agent_id' => $existingContactAgentId,
                    'name'                      => $leadName,
                    'email'                     => strtolower(str_replace(' ', '.', $leadName)) . '@example.com',
                    'phone'                     => $this->fakePhone($i),
                    'message'                   => 'Hi, I\'m interested in ' . $property->address . ', ' . $property->suburb . '. Is it still available?',
                    'is_whatsapp'               => $isWhatsApp,
                    'lead_source_raw'           => json_encode(['source' => 'demo_seed', 'portal' => $portal, 'ref' => $ref]),
                    'received_at'               => $receivedAt,
                    'notified_at'               => $notifiedAt,
                ]
            );

            if ($lead->wasRecentlyCreated) {
                $inserted++;
            }
            $i++;
        }

        $note = "Portal leads: +{$inserted}, now " . ($already + $inserted) . '/' . self::TARGET_TOTAL . '.';

        return ['inserted' => $inserted, 'note' => $note];
    }

    private function fakePhone(int $seed): string
    {
        return '072' . str_pad((string) (1000000 + ($seed * 53) % 8999999), 7, '0', STR_PAD_LEFT);
    }
}
