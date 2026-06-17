<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\User;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-45 — Contact messaging opt-in marker.
 *
 * Mirrors the opt-out triplet (migration 2026_05_14_080004). Opt-in is a
 * recorded FACT: it sets the messaging_opt_in_* columns + isOptedIn(), is
 * recorded through a route/permission gate identical to opt-out, is INDEPENDENT
 * of opt-out (does not clear it), and does NOT change the send gate.
 */
final class MessagingOptInTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_opt_in_model_method_sets_triplet_and_helper(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);

        $this->assertFalse($contact->isOptedIn());

        $contact->recordOptIn('YES via WhatsApp', $userId);
        $contact->refresh();

        $this->assertNotNull($contact->messaging_opted_in_at);
        $this->assertSame('YES via WhatsApp', $contact->messaging_opt_in_reason);
        $this->assertSame($userId, (int) $contact->messaging_opt_in_recorded_by_user_id);
        $this->assertTrue($contact->isOptedIn());
    }

    public function test_opt_in_route_records_consent_and_redirects_back(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->from(route('seller-outreach.composer.timeline', $contact))
            ->post(route('seller-outreach.composer.opt-in', $contact), [
                'reason' => 'Seller replied YES via WhatsApp',
            ]);

        $resp->assertStatus(302);
        $resp->assertSessionHas('status');

        $contact->refresh();
        $this->assertTrue($contact->isOptedIn());
        $this->assertSame('Seller replied YES via WhatsApp', $contact->messaging_opt_in_reason);
        $this->assertSame($userId, (int) $contact->messaging_opt_in_recorded_by_user_id);
    }

    public function test_opt_in_requires_a_reason(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);

        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.composer.opt-in', $contact), [])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($contact->fresh()->isOptedIn());
    }

    public function test_opt_in_and_opt_out_are_independent_facts(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);

        // Opt the contact OUT first (the hard block).
        $contact->update([
            'messaging_opt_out_at'                  => now(),
            'messaging_opt_out_reason'              => 'STOP via WhatsApp',
            'messaging_opt_out_recorded_by_user_id' => $userId,
        ]);

        // Record an opt-in afterwards (the re-consent path) — it must NOT clear
        // the opt-out. Both facts coexist; the send gate still honours opt-out.
        $contact->recordOptIn('Later replied YES', $userId);
        $contact->refresh();

        $this->assertTrue($contact->isOptedIn(), 'opt-in recorded');
        $this->assertNotNull($contact->messaging_opt_out_at, 'opt-out untouched by opt-in');
        $this->assertSame('STOP via WhatsApp', $contact->messaging_opt_out_reason);
    }

    public function test_agent_opt_in_re_enables_a_marketing_opted_out_contact_via_the_consent_spine(): void
    {
        // The verbal-consent gap: a contact previously opted out, then later
        // gives explicit consent on a call. The agent's opt-in must fully
        // re-enable (not just record a fact) through MarketingConsentService.
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->actingAs(User::find($userId))->seedContact($agencyId);
        $svc = app(MarketingConsentService::class);

        // Fully opt the contact out (opt-out triplet + all-blocked latch +
        // channel booleans + identifier marketing suppression).
        $svc->optOutContact(
            contact: $contact, reason: 'STOP via WhatsApp', source: 'agent',
            actorUserId: $userId, blockAll: true,
        );
        $contact->refresh();
        $this->assertNotNull($contact->messaging_opt_out_at, 'pre: opted out');
        $this->assertTrue((bool) $contact->messaging_all_blocked, 'pre: all blocked');
        $this->assertFalse($contact->canSendVia('whatsapp'), 'pre: send gate closed');
        $this->assertTrue($svc->isContactSuppressed($contact), 'pre: identifier suppressed');

        // Agent records the verbal consent via the timeline opt-in route.
        $this->actingAs(User::find($userId))
            ->from(route('seller-outreach.composer.timeline', $contact))
            ->post(route('seller-outreach.composer.opt-in', $contact), [
                'reason' => 'Seller gave verbal consent by phone on 17 Jun',
            ])
            ->assertStatus(302)
            ->assertSessionHas('status');

        $contact->refresh();
        // Opt-out fully lifted + gate reopened.
        $this->assertNull($contact->messaging_opt_out_at, 'opt-out lifted');
        $this->assertFalse((bool) $contact->messaging_all_blocked, 'all-blocked latch cleared');
        $this->assertFalse($svc->isContactSuppressed($contact), 'suppression lifted');
        $this->assertTrue($contact->canSendVia('whatsapp'), 'send gate reopened');
        // Consent landed on the spine, stamped with the agent + the reason/method.
        $this->assertTrue($contact->isOptedIn());
        $this->assertSame('Seller gave verbal consent by phone on 17 Jun', $contact->messaging_opt_in_reason);
        $this->assertSame($userId, (int) $contact->messaging_opt_in_recorded_by_user_id);
        $this->assertTrue(
            $contact->consentRecords()
                ->where('consent_type', MarketingConsentService::CONSENT_MARKETING)
                ->whereNull('revoked_at')
                ->exists(),
            'an active marketing consent record was written to the spine'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedContact(int $agencyId): Contact
    {
        return Contact::create([
            'agency_id'  => $agencyId,
            'branch_id'  => $agencyId,
            'first_name' => 'Test',
            'last_name'  => 'Seller',
            'phone'      => '0821234567',
            'email'      => 'seller@test.example',
        ]);
    }
}
