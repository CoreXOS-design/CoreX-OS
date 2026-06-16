<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\SellerOutreach\TransactionStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-50 — 3-state communication status + transaction-lock gating.
 *
 * The lock fires on ANY of: active deals_v2 (seller/buyer), a live MANDATE on a
 * property the contact owns/sells, or that property being currently ADVERTISED
 * (P24 / PP / own website). Marketing can always be switched off; the
 * transaction switch is locked while any of those is live, and lifts when none is.
 */
final class CommunicationStatusGatingTest extends TestCase
{
    use RefreshDatabase;

    // ── (a) seller on an active deal → locked ────────────────────────────
    public function test_seller_on_active_deal_locks_transaction_switch_but_marketing_optout_works(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $property = $this->seedProperty($agencyId, $userId);
        $this->seedDeal($agencyId, $userId, $property, $contact, 'seller', status: 'active', registered: false);
        $send = $this->seedSend($agencyId, $userId, $contact, $property);

        $svc = app(TransactionStateService::class);
        $this->assertTrue($svc->isInLiveTransaction($agencyId, $contact), 'seller on active deal is live');

        // GET screen: transaction switch LOCKED with the named sale; no "stop all".
        $resp = $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token))->assertOk();
        $resp->assertSee('Messages about my transaction', false);
        $resp->assertSee("we're required to keep you updated", false);
        $resp->assertSee('an active sale', false);
        $resp->assertDontSee('Stop all messages', false);

        // Marketing opt-out still works and blocks marketing sends.
        $this->post(route('seller-outreach.public.opt-out.confirm', $send->opt_out_token), ['action' => 'stop_marketing'])->assertOk();
        $contact->refresh();
        $this->assertNotNull($contact->messaging_opt_out_at, 'marketing opted out');
        $this->assertFalse($contact->canSendVia('whatsapp'), 'marketing send blocked');
        $this->assertSame(Contact::COMM_TRANSACTION_ONLY, $contact->communicationStatus(), '(e) transaction_only badge');
    }

    // ── (b) buyer on an active deal → locked ─────────────────────────────
    public function test_buyer_on_active_deal_is_locked(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $property = $this->seedProperty($agencyId, $userId);
        $this->seedDeal($agencyId, $userId, $property, $contact, 'buyer', status: 'active', registered: false);
        $send = $this->seedSend($agencyId, $userId, $contact, $property);

        $this->assertTrue(app(TransactionStateService::class)->isInLiveTransaction($agencyId, $contact));
        $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token))
            ->assertOk()->assertSee("we're required to keep you updated", false)->assertDontSee('Stop all messages', false);
    }

    // ── (c) no active deal → switch enabled → full opt-out works ─────────
    public function test_no_active_transaction_allows_full_optout(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $property = $this->seedProperty($agencyId, $userId);
        $send = $this->seedSend($agencyId, $userId, $contact, $property);

        $this->assertFalse(app(TransactionStateService::class)->isInLiveTransaction($agencyId, $contact));
        $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token))
            ->assertOk()->assertSee('Stop all messages', false)->assertDontSee("we're required to keep you updated", false);

        $this->post(route('seller-outreach.public.opt-out.confirm', $send->opt_out_token), ['action' => 'stop_all'])->assertOk();
        $contact->refresh();
        $this->assertNotNull($contact->messaging_opt_out_at);
        $this->assertSame(Contact::COMM_MARKETING_OPTED_OUT, $contact->communicationStatus(), '(e) marketing_opted_out badge');
    }

    // ── (d) deal registered → lock lifts ─────────────────────────────────
    public function test_registered_deal_lifts_the_lock(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $property = $this->seedProperty($agencyId, $userId);
        $this->seedDeal($agencyId, $userId, $property, $contact, 'seller', status: 'active', registered: true);
        $send = $this->seedSend($agencyId, $userId, $contact, $property);

        $this->assertFalse(app(TransactionStateService::class)->isInLiveTransaction($agencyId, $contact), 'registered deal is concluded');
        $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token))->assertOk()->assertSee('Stop all messages', false);
    }

    // ── (e) badge: clean opted-in contact ────────────────────────────────
    public function test_opted_in_contact_badge(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $this->assertSame(Contact::COMM_OPTED_IN, $contact->communicationStatus());
    }

    // ── (g) seller under a live MANDATE, no deal → locked ────────────────
    public function test_live_mandate_without_deal_locks(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        // Live mandate: future expiry, non-dead status, NOT advertised.
        $property = $this->seedProperty($agencyId, $userId, [
            'expiry_date' => now()->addMonths(2)->toDateString(),
            'status'      => 'active',
        ]);
        $this->linkContactToProperty($contact, $property, 'owner');
        $send = $this->seedSend($agencyId, $userId, $contact, $property);

        $this->assertTrue(app(TransactionStateService::class)->isInLiveTransaction($agencyId, $contact), 'live mandate locks');
        $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token))
            ->assertOk()->assertSee('active mandate', false);
    }

    // ── (h) property currently ADVERTISED → locked ───────────────────────
    public function test_advertised_property_locks(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        // Expired mandate by date, BUT advertised on P24 → still live.
        $property = $this->seedProperty($agencyId, $userId, [
            'expiry_date'            => now()->subMonths(2)->toDateString(),
            'status'                 => 'expired',
            'p24_syndication_status' => 'active',
        ]);
        $this->linkContactToProperty($contact, $property, 'seller');
        $send = $this->seedSend($agencyId, $userId, $contact, $property);

        $this->assertTrue(app(TransactionStateService::class)->isInLiveTransaction($agencyId, $contact), 'advertised property locks');
        $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token))
            ->assertOk()->assertSee('currently advertised', false);
    }

    // ── (i) expired mandate + no syndication + no deal → lock lifts ──────
    public function test_expired_mandate_and_inactive_syndication_lifts_lock(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $property = $this->seedProperty($agencyId, $userId, [
            'expiry_date'            => now()->subMonths(2)->toDateString(),
            'status'                 => 'expired',
            'p24_syndication_status' => 'inactive',
            'pp_syndication_status'  => null,
        ]);
        $this->linkContactToProperty($contact, $property, 'owner');
        $send = $this->seedSend($agencyId, $userId, $contact, $property);

        $this->assertFalse(app(TransactionStateService::class)->isInLiveTransaction($agencyId, $contact), 'expired + unadvertised + no deal lifts');
        $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token))->assertOk()->assertSee('Stop all messages', false);
    }

    // ── (f) generic /unsubscribe respects the gate ───────────────────────
    public function test_unsubscribe_page_respects_transaction_gate(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $property = $this->seedProperty($agencyId, $userId);
        $this->seedDeal($agencyId, $userId, $property, $contact, 'seller', status: 'active', registered: false);

        // In a live sale → page explains transactional comms continue.
        $this->post(route('seller-outreach.public.unsubscribe.submit', $agencyId), ['identifier' => $contact->email])
            ->assertOk()->assertSee('essential updates about that sale', false);
        $this->assertNotNull($contact->fresh()->messaging_opt_out_at, 'marketing still suppressed');

        // A different, non-transaction contact → no transactional message.
        $other = $this->seedContact($agencyId, 'other@test.example', '0830001111');
        $this->post(route('seller-outreach.public.unsubscribe.submit', $agencyId), ['identifier' => $other->email])
            ->assertOk()->assertDontSee('essential updates about that sale', false);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        return [$agencyId, $user->id];
    }

    private function seedContact(int $agencyId, string $email = 'seller@test.example', string $phone = '0821234567'): Contact
    {
        return Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'Thandi', 'last_name' => 'Mkhize', 'phone' => $phone, 'email' => $email,
        ]);
    }

    private function seedProperty(int $agencyId, int $userId, array $overrides = []): int
    {
        return (int) DB::table('properties')->insertGetId(array_merge([
            'external_id' => 'TEST-' . Str::random(8), 'title' => '14 Marine Drive',
            'address' => '14 Marine Drive', 'suburb' => 'Uvongo', 'price' => 1_850_000,
            'property_type' => 'house', 'status' => 'active', 'is_demo' => false,
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function linkContactToProperty(Contact $contact, int $propertyId, string $role): void
    {
        DB::table('contact_property')->insert([
            'contact_id' => $contact->id, 'property_id' => $propertyId, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedDeal(int $agencyId, int $userId, int $propertyId, Contact $contact, string $role, string $status, bool $registered): int
    {
        $templateId = (int) DB::table('deal_pipeline_templates')->insertGetId([
            'name' => 'Standard', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'created_by_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dealId = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'D-' . Str::random(6), 'deal_type' => 'bond', 'status' => $status,
            'property_id' => $propertyId, 'listing_agent_id' => $userId, 'pipeline_template_id' => $templateId,
            'purchase_price' => 1_850_000, 'commission_amount' => 92_500, 'commission_vat' => 13_875,
            'offer_date' => now()->toDateString(),
            'actual_registration' => $registered ? now()->toDateString() : null,
            'branch_id' => $agencyId, 'agency_id' => $agencyId, 'created_by_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_v2_contacts')->insert([
            'deal_id' => $dealId, 'contact_id' => $contact->id, 'role' => $role,
            'created_at' => now(),
        ]);
        return $dealId;
    }

    private function seedSend(int $agencyId, int $userId, Contact $contact, int $propertyId): SellerOutreachSend
    {
        return SellerOutreachSend::create([
            'agency_id' => $agencyId, 'contact_id' => $contact->id, 'property_id' => $propertyId, 'agent_id' => $userId,
            'channel' => 'whatsapp', 'body_snapshot' => 'Hi. Tap the link or reply STOP.', 'facts_snapshot' => ['merge_fields' => []],
            'tracking_short_code' => Str::random(6), 'opt_out_token' => Str::random(48),
            'sent_at' => now(), 'outcome' => SellerOutreachSend::OUTCOME_SENT,
        ]);
    }
}
