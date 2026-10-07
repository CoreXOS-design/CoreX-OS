<?php

declare(strict_types=1);

namespace Tests\Feature\LeadResponse;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\ContactMatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * lead-response:backfill — only provable history fills an old enquiry; everything else stays "Not measured".
 */
final class LeadResponseBackfillTest extends LeadResponseTestCase
{
    private function sentMessage(\App\Models\Contact $contact, string $at, \App\Models\User $owner): void
    {
        $comm = Communication::create([
            'agency_id' => $this->agency->id, 'channel' => Communication::CHANNEL_WHATSAPP, 'direction' => Communication::DIRECTION_OUTBOUND,
            'external_id' => Str::random(12), 'thread_key' => 'tk', 'from_identifier' => '2782', 'occurred_at' => $at, 'captured_at' => $at,
            'owner_user_id' => $owner->id,
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agency->id, 'communication_id' => $comm->id, 'linkable_type' => \App\Models\Contact::class,
            'linkable_id' => $contact->id, 'link_method' => CommunicationLink::METHOD_DETERMINISTIC, 'confidence' => 100, 'confirmed_at' => $at,
        ]);
    }

    private function lead2(array $o): \App\Models\PortalLead
    {
        return $this->lead($o + ['tracked' => false]);
    }

    public function test_dry_run_changes_nothing_and_apply_fills_only_provable_history_once(): void
    {
        $c1 = $this->contact('Pro', $this->agentA);
        $provable = $this->lead2(['contact' => $c1, 'received' => '2026-09-20 09:00:00']);
        $this->sentMessage($c1, '2026-09-20 08:00:00', $this->agentA);   // BEFORE the lead — ignored
        $this->sentMessage($c1, '2026-09-20 10:00:00', $this->agentB);   // the first after it
        $this->sentMessage($c1, '2026-09-21 10:00:00', $this->agentA);   // later — not the first

        $unprovable = $this->lead2(['received' => '2026-09-20 09:00:00']);   // nothing in history
        $already = $this->lead(['received' => '2026-10-06 09:00:00']);        // tracked, left alone

        Artisan::call('lead-response:backfill', ['--dry-run' => true]);
        $this->assertFalse((bool) $provable->fresh()->response_tracked);
        $this->assertNull($provable->fresh()->first_response_at);

        Artisan::call('lead-response:backfill');
        $p = $provable->fresh();
        $this->assertTrue((bool) $p->response_tracked);
        $this->assertSame('2026-09-20 10:00:00', $p->first_response_at->format('Y-m-d H:i:s'));
        $this->assertSame('message', $p->first_response_channel);
        $this->assertSame($this->agentB->id, (int) $p->first_response_by_user_id);

        $u = $unprovable->fresh();
        $this->assertFalse((bool) $u->response_tracked, 'no proof, no guess: stays Not measured');
        $this->assertNull($u->first_response_at);
        $this->assertNull($already->fresh()->first_response_at);
        $this->assertTrue((bool) $already->fresh()->response_tracked);

        // idempotent
        Artisan::call('lead-response:backfill');
        $this->assertSame('2026-09-20 10:00:00', $provable->fresh()->first_response_at->format('Y-m-d H:i:s'));
    }

    public function test_an_unconfirmed_share_and_a_provisional_message_are_not_proof(): void
    {
        $c = $this->contact('Nope', $this->agentA);
        $lead = $this->lead2(['contact' => $c, 'received' => '2026-09-20 09:00:00']);
        ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $c->id, 'created_by_user_id' => $this->agentA->id, 'agent_id' => $this->agentA->id,
            'name' => 'W', 'listing_type' => 'sale', 'status' => ContactMatch::STATUS_ACTIVE,
        ])->mintShareLink($this->agentA->id); // minted, never confirmed

        Artisan::call('lead-response:backfill');

        $this->assertNull($lead->fresh()->first_response_at);
    }

    public function test_a_confirmed_share_is_proof(): void
    {
        $c = $this->contact('Yes', $this->agentA);
        $lead = $this->lead2(['contact' => $c, 'received' => '2026-09-20 09:00:00']);
        $match = ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $c->id, 'created_by_user_id' => $this->agentA->id, 'agent_id' => $this->agentA->id,
            'name' => 'W', 'listing_type' => 'sale', 'status' => ContactMatch::STATUS_ACTIVE,
        ]);
        $share = $match->mintShareLink($this->agentA->id);
        $share->forceFill(['confirmed_at' => '2026-09-20 11:00:00'])->save();

        Artisan::call('lead-response:backfill');

        $l = $lead->fresh();
        $this->assertSame('shared_link', $l->first_response_channel);
        $this->assertSame('2026-09-20 11:00:00', $l->first_response_at->format('Y-m-d H:i:s'));
    }
}
