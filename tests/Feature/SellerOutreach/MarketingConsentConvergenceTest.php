<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Events\SellerOutreach\OptOutRecorded;
use App\Models\Contact;
use App\Models\MarketingSuppression;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-49 — "one opt-out, suppressed everywhere" convergence.
 *
 * Covers the pieces the opt-out-link test does not: the self-service opt-IN link
 * (reverses all four stores + lifts the suppression), the generic /unsubscribe
 * page (by email and by phone, plus the unknown-identifier case that must STILL
 * record a suppression row), identifier-level blocking of a re-imported number,
 * and the single MarketingConsentService convergence point that BOTH the agent
 * opt-out and the link opt-out reach through RecordOptOutOnContact.
 */
final class MarketingConsentConvergenceTest extends TestCase
{
    use RefreshDatabase;

    /** Normalised SA core for the seeded phone 0821234567. */
    private const PHONE_CORE = '821234567';
    private const EMAIL = 'seller@test.example';

    // ── Self-service opt-IN link ─────────────────────────────────────────

    public function test_opt_in_link_reverses_all_four_stores_and_lifts_suppression(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $send = $this->seedSend($agencyId, $userId, $contact);

        // First opt the contact out via the link — sets the flag, channel
        // booleans, and an identifier-level suppression row.
        $this->post(route('seller-outreach.public.opt-out.confirm', $send->opt_out_token))->assertOk();
        $contact->refresh();
        $this->assertNotNull($contact->messaging_opt_out_at, 'pre-condition: opted out');
        $this->assertTrue((bool) $contact->opt_out_whatsapp, 'pre-condition: channel off');
        $this->assertTrue($this->suppressionActive($agencyId, self::PHONE_CORE), 'pre-condition: phone suppressed');

        // GET is preview-safe (no write).
        $this->get(route('seller-outreach.public.opt-in.show', $send->opt_out_token))
            ->assertOk()
            ->assertSee('Get marketing updates', false);
        $this->assertNotNull($contact->fresh()->messaging_opt_out_at, 'GET must not opt in');

        // POST runs the full reverse.
        $this->post(route('seller-outreach.public.opt-in.confirm', $send->opt_out_token))
            ->assertOk()
            ->assertSee('receive marketing updates', false);

        $contact->refresh();
        // (2) opt-out triplet cleared; opt-in marker stamped.
        $this->assertNull($contact->messaging_opt_out_at);
        $this->assertNull($contact->messaging_opt_out_source);
        $this->assertNotNull($contact->messaging_opted_in_at);
        // (3) channel booleans back on.
        $this->assertFalse((bool) $contact->opt_out_whatsapp);
        $this->assertFalse((bool) $contact->opt_out_email);
        // (4) suppression lifted.
        $this->assertFalse($this->suppressionActive($agencyId, self::PHONE_CORE), 'phone suppression lifted');
        $this->assertFalse($this->suppressionActive($agencyId, self::EMAIL), 'email suppression lifted');
        // The end state: the contact is sendable again.
        $this->assertTrue($contact->canSendVia('whatsapp'));
    }

    // ── Generic /unsubscribe page ────────────────────────────────────────

    public function test_unsubscribe_by_email_suppresses_and_opts_out_matched_contact(): void
    {
        [$agencyId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);

        $this->post(route('seller-outreach.public.unsubscribe.submit', $agencyId), [
            'identifier' => self::EMAIL,
        ])->assertOk()->assertSee('request has been processed', false);

        $contact->refresh();
        $this->assertNotNull($contact->messaging_opt_out_at, 'matched contact opted out');
        $this->assertTrue($this->suppressionActive($agencyId, self::EMAIL), 'email suppressed');
    }

    public function test_unsubscribe_by_phone_suppresses_matched_contact(): void
    {
        [$agencyId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);

        $this->post(route('seller-outreach.public.unsubscribe.submit', $agencyId), [
            'identifier' => '082 123 4567',
        ])->assertOk();

        $this->assertNotNull($contact->fresh()->messaging_opt_out_at);
        $this->assertTrue($this->suppressionActive($agencyId, self::PHONE_CORE), 'phone suppressed');
    }

    public function test_unsubscribe_unknown_identifier_still_records_a_suppression(): void
    {
        [$agencyId] = $this->seedAgency();

        $this->post(route('seller-outreach.public.unsubscribe.submit', $agencyId), [
            'identifier' => 'stranger@nowhere.example',
        ])->assertOk();

        // No contact matched, but the identifier MUST be blocked for any future import.
        $row = MarketingSuppression::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('identifier', 'stranger@nowhere.example')
            ->first();
        $this->assertNotNull($row, 'unknown identifier still suppressed');
        $this->assertNull($row->contact_id, 'no contact attached');
        $this->assertSame(MarketingSuppression::SOURCE_UNSUBSCRIBE_PAGE, $row->source);
    }

    // ── Re-imported identifier stays blocked ─────────────────────────────

    public function test_reimported_suppressed_number_stays_blocked_on_a_fresh_contact(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $send = $this->seedSend($agencyId, $userId, $contact);

        // Opt out the original contact (creates the identifier suppression).
        $this->post(route('seller-outreach.public.opt-out.confirm', $send->opt_out_token))->assertOk();

        // A NEW contact is imported carrying the SAME phone number, with NO
        // opt-out flag of its own — it must still be blocked agency-wide.
        $reimported = Contact::create([
            'agency_id'  => $agencyId,
            'branch_id'  => $agencyId,
            'first_name' => 'Re',
            'last_name'  => 'Import',
            'phone'      => '0821234567',
            'email'      => 'different@test.example',
        ]);

        $this->assertNull($reimported->messaging_opt_out_at, 'no flag on the fresh contact');
        $this->assertTrue(
            app(MarketingConsentService::class)->isContactSuppressed($reimported),
            'fresh contact is suppressed by shared identifier'
        );
        $this->assertFalse($reimported->canSendVia('whatsapp'), 'send gate blocks the re-import');
    }

    // ── Both opt-out sources converge through MarketingConsentService ────

    public function test_agent_and_link_opt_out_both_converge_through_the_service(): void
    {
        [$agencyId, $userId] = $this->seedAgency();

        // (A) Agent-marked opt-out — fired with SOURCE_AGENT and a real actor.
        $agentContact = $this->seedContact($agencyId);
        OptOutRecorded::dispatch($agentContact, null, 'Agent marked STOP', $userId, $agencyId, OptOutRecorded::SOURCE_AGENT);

        $agentContact->refresh();
        $this->assertNotNull($agentContact->messaging_opt_out_at);
        $this->assertTrue((bool) $agentContact->opt_out_whatsapp, 'agent path set channel booleans');
        $agentRow = MarketingSuppression::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('identifier', self::PHONE_CORE)->whereNull('lifted_at')->first();
        $this->assertNotNull($agentRow, 'agent path wrote a suppression');
        $this->assertSame(MarketingSuppression::SOURCE_AGENT, $agentRow->source);
        $this->assertSame($userId, (int) $agentRow->recorded_by_user_id);

        // (B) Link opt-out — same service, via the public controller (no actor).
        $linkContact = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'Link', 'last_name' => 'Optout',
            'phone' => '0739998888', 'email' => 'link@test.example',
        ]);
        $send = $this->seedSend($agencyId, $userId, $linkContact);
        $this->post(route('seller-outreach.public.opt-out.confirm', $send->opt_out_token))->assertOk();

        $linkRow = MarketingSuppression::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('identifier', '739998888')->whereNull('lifted_at')->first();
        $this->assertNotNull($linkRow, 'link path wrote a suppression through the same service');
        $this->assertSame(OptOutRecorded::SOURCE_SELF_SERVICE_LINK, $linkRow->source);
        $this->assertNull($linkRow->recorded_by_user_id, 'public link has no actor');
    }

    // ── Helpers (mirror PublicOptOutLinkTest) ────────────────────────────

    private function suppressionActive(int $agencyId, string $identifier): bool
    {
        return MarketingSuppression::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('identifier', $identifier)
            ->whereNull('lifted_at')
            ->exists();
    }

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
            'email'      => self::EMAIL,
        ]);
    }

    private function seedProperty(int $agencyId, int $userId): int
    {
        return (int) DB::table('properties')->insertGetId([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => '18 Golf Course Road',
            'address'       => '18 Golf Course Road',
            'suburb'        => 'Uvongo',
            'price'         => 1_200_000,
            'property_type' => 'house',
            'status'        => 'active',
            'is_demo'       => false,
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $userId,
            'created_at'    => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSend(int $agencyId, int $userId, Contact $contact): SellerOutreachSend
    {
        $propertyId = $this->seedProperty($agencyId, $userId);

        return SellerOutreachSend::create([
            'agency_id'           => $agencyId,
            'contact_id'          => $contact->id,
            'property_id'         => $propertyId,
            'agent_id'            => $userId,
            'channel'             => 'whatsapp',
            'body_snapshot'       => 'Hi Test. Tap the link or reply STOP.',
            'facts_snapshot'      => ['merge_fields' => []],
            'tracking_short_code' => Str::random(6),
            'opt_out_token'       => Str::random(48),
            'sent_at'             => now(),
            'outcome'             => SellerOutreachSend::OUTCOME_SENT,
        ]);
    }
}
