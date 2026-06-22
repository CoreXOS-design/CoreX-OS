<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Events\SellerOutreach\OptOutRecorded;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-49 — public self-service marketing opt-out link.
 *
 * Covers the full input space: preview-safe GET (no write), POST records a
 * link-sourced opt-out (flag + reason + source, recorder NULL), the agency-wide
 * send block that follows, idempotent re-POST, and 404 on an unknown token.
 */
final class PublicOptOutLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // AT-83 — the page now pre-warms the agent-card image (og:image); keep
        // those writes in a temp disk so the suite leaves no real artefacts.
        Storage::fake('public');
    }

    /**
     * AT-83 — this preference page is the SINGLE outreach link, so it hosts the
     * WhatsApp agent-card OG tags. A bot GET must see the agent-card og:image
     * (no opt-in page split anymore).
     */
    public function test_get_emits_agent_card_og_tags(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $send = $this->seedSend($agencyId, $userId, $contact);

        $resp = $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token));

        $resp->assertStatus(200);
        $resp->assertSee('property="og:image"', false);
        $resp->assertSee('/outreach/agent-card/' . $userId . '.jpg', false);
        $resp->assertSee('summary_large_image', false);
        // og:title resolves to the AGENT card (designation default), not the
        // agency-only fallback — proves the agent path fired.
        $resp->assertSee('Property Practitioner at', false);
    }

    public function test_get_is_preview_safe_and_does_not_opt_out(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $send = $this->seedSend($agencyId, $userId, $contact);

        $resp = $this->get(route('seller-outreach.public.opt-out.show', $send->opt_out_token));

        $resp->assertStatus(200);
        // AT-50 — the link now renders the two-switch communication-preferences screen.
        $resp->assertSee('Your communication preferences', false);
        $resp->assertSee('Marketing &amp; area updates', false);
        // PREVIEW-SAFE: a crawler / WhatsApp link-preview GET must NOT opt anyone out.
        $this->assertNull($contact->fresh()->messaging_opt_out_at);
    }

    public function test_post_records_link_sourced_opt_out(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $send = $this->seedSend($agencyId, $userId, $contact);

        // No `action` param → defaults to stop_marketing (turn marketing off).
        $resp = $this->post(route('seller-outreach.public.opt-out.confirm', $send->opt_out_token));

        $resp->assertStatus(200);
        $resp->assertSee('Your preferences have been updated', false);

        $contact->refresh();
        $this->assertNotNull($contact->messaging_opt_out_at, 'opt-out flag set');
        $this->assertSame('Self-service opt-out link', $contact->messaging_opt_out_reason);
        $this->assertSame(OptOutRecorded::SOURCE_SELF_SERVICE_LINK, $contact->messaging_opt_out_source);
        // No authenticated user on a public link → recorder is NULL, not a user id.
        $this->assertNull($contact->messaging_opt_out_recorded_by_user_id);
    }

    public function test_idempotent_repost_preserves_original_record(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $contact = $this->seedContact($agencyId);
        $send = $this->seedSend($agencyId, $userId, $contact);

        // Pre-existing opt-out (e.g. agent-marked earlier) with a known timestamp.
        $original = now()->subDays(3)->startOfSecond();
        $contact->update([
            'messaging_opt_out_at'                  => $original,
            'messaging_opt_out_reason'              => 'STOP via WhatsApp',
            'messaging_opt_out_recorded_by_user_id' => $userId,
            'messaging_opt_out_source'              => OptOutRecorded::SOURCE_AGENT,
        ]);

        $resp = $this->post(route('seller-outreach.public.opt-out.confirm', $send->opt_out_token));

        $resp->assertStatus(200);
        $resp->assertSee('Your preferences have been updated', false);

        $contact->refresh();
        // Idempotent: original record untouched (timestamp, reason, source, recorder).
        $this->assertEquals($original->toDateTimeString(), $contact->messaging_opt_out_at->toDateTimeString());
        $this->assertSame('STOP via WhatsApp', $contact->messaging_opt_out_reason);
        $this->assertSame(OptOutRecorded::SOURCE_AGENT, $contact->messaging_opt_out_source);
        $this->assertSame($userId, (int) $contact->messaging_opt_out_recorded_by_user_id);
    }

    public function test_unknown_token_404s_on_get_and_post(): void
    {
        $token = Str::random(48);
        $this->get(route('seller-outreach.public.opt-out.show', $token))->assertStatus(404);
        $this->post(route('seller-outreach.public.opt-out.confirm', $token))->assertStatus(404);
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
            'body_snapshot'       => 'Hi Test. Tap https://example.test/outreach/opt-out/xyz or reply STOP.',
            'facts_snapshot'      => ['merge_fields' => []],
            'tracking_short_code' => Str::random(6),
            'opt_out_token'       => Str::random(48),
            'sent_at'             => now(),
            'outcome'             => SellerOutreachSend::OUTCOME_SENT,
        ]);
    }
}
