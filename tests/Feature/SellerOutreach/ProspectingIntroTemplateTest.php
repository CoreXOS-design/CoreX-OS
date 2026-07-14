<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use Database\Seeders\HfcConsentTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-263 — Johan's prospecting introduction, as an EMAIL template and a WHATSAPP
 * template in the seller-outreach family.
 *
 * The point of this test is that the copy is not just stored — it RESOLVES. Every
 * placeholder Johan wrote maps to a real merge field with a real data source, and
 * the proof is a rendered message against a real contact + property + agent with
 * no braces left in it.
 *
 * The mapping under test (his token → the real one):
 *   {first_name} → {seller_name} · {area} → {property_suburb}
 *   {property_ref} → {property_address} · {ffc_number} → {agent_ffc} (the AGENT's
 *   FFC, users.ffc_number — not the agency's) · {agency_phone} → {branch_or_company_tel}
 *
 * And the two compliance facts his draft could not have known: {opt_out_link} is
 * mandatory on every outreach template (AT-49/POPIA — the validator hard-blocks
 * without it), and the blank-address gate still governs the send.
 */
final class ProspectingIntroTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'Prospecting Introduction — Sales & Rentals';

    /** The seeder is hardcoded to HFC (agency 1) — that is the library it owns. */
    private const AGENCY_ID = 1;

    /**
     * The seeder runs the REAL SellerOutreachTemplateValidator and throws on a
     * failure, so simply reaching a seeded row proves the copy satisfies every
     * hard rule: {opt_out_link} present, an opt-out keyword present, and a
     * non-empty subject on the email channel.
     */
    public function test_the_template_ships_on_both_channels_and_passes_the_real_validator(): void
    {
        $this->seedAgency();
        $this->runSeeder();

        $email = $this->template('email');
        $whatsapp = $this->template('whatsapp');

        $this->assertNotNull($email, 'the email template must exist');
        $this->assertNotNull($whatsapp, 'the WhatsApp template must exist');

        // (3) The email channel carries a subject; WhatsApp has no such concept.
        $this->assertSame('Marketing your property in {property_suburb} — a short call?', $email->subject);
        $this->assertNull($whatsapp->subject);

        // Same copy on both channels — one definition, two surfaces.
        $this->assertSame($email->body, $whatsapp->body);

        // A cold introduction carries no live-demand link.
        $this->assertFalse((bool) $email->include_tracking_link);

        // Both ship usable, and neither steals the standing default.
        foreach ([$email, $whatsapp] as $t) {
            $this->assertTrue((bool) $t->is_active);
            $this->assertFalse((bool) $t->is_default_for_channel);
        }
    }

    /** The email message, resolved against real records. */
    public function test_the_email_renders_with_every_placeholder_resolved(): void
    {
        $this->seedAgency();
        $this->runSeeder();
        [$contact, $property, $agent] = $this->seedWorld();

        $ctx = $this->compose('email', $contact, $property, $agent);

        // The subject resolves too — not just the body.
        $this->assertSame('Marketing your property in Uvongo — a short call?', $ctx->renderedSubject);

        $body = $ctx->renderedBody;
        $this->assertStringContainsString('Good day Thandi,', $body);                     // {first_name}
        $this->assertStringContainsString('My name is Nomsa Dlamini', $body);             // {agent_name}
        $this->assertStringContainsString('from Home Finders Coastal', $body);            // {agency_name}
        $this->assertStringContainsString('(registered with the PPRA, FFC FFC-2026-4471)', $body); // {ffc_number} → agent's own
        $this->assertStringContainsString('property owners in Uvongo', $body);            // {area}
        $this->assertStringContainsString('your property at 1 Alamien Avenue, Uvongo', $body); // {property_ref}
        $this->assertStringContainsString('Nomsa Dlamini | Home Finders Coastal | 039 315 0000', $body); // {agency_phone}

        // His own words survived the mapping intact.
        $this->assertStringContainsString('When would be a good time for a short call?', $body);
        $this->assertStringContainsString('simply reply STOP', $body);

        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
        $this->assertNoUnresolvedTokens($body);
    }

    /** The WhatsApp message resolves identically — same copy, same data. */
    public function test_the_whatsapp_message_renders_with_every_placeholder_resolved(): void
    {
        $this->seedAgency();
        $this->runSeeder();
        [$contact, $property, $agent] = $this->seedWorld();

        $ctx = $this->compose('whatsapp', $contact, $property, $agent);
        $body = $ctx->renderedBody;

        $this->assertStringContainsString('Good day Thandi,', $body);
        $this->assertStringContainsString('(registered with the PPRA, FFC FFC-2026-4471)', $body);
        $this->assertStringContainsString('your property at 1 Alamien Avenue, Uvongo', $body);
        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
        $this->assertNoUnresolvedTokens($body);
    }

    /**
     * The FFC is the sending AGENT's, and an agent without one on file must not
     * send a message reading "FFC " with nothing after it. The optional segment
     * collapses the whole clause instead.
     */
    public function test_an_agent_with_no_ffc_reads_cleanly_and_never_shows_a_dangling_ffc_label(): void
    {
        $this->seedAgency();
        $this->runSeeder();
        [$contact, $property] = $this->seedWorld();
        $agentWithoutFfc = $this->seedAgent(ffc: null);

        $body = $this->compose('email', $contact, $property, $agentWithoutFfc)->renderedBody;

        $this->assertStringContainsString('(registered with the PPRA)', $body);
        $this->assertStringNotContainsString('FFC', $body, 'no dangling FFC label when the agent has none');
        $this->assertNoUnresolvedTokens($body);
    }

    /**
     * (2) The standing compliance gates still govern this template. The blank-address
     * gate is the one that bites here, because the copy names the property.
     */
    public function test_the_blank_address_send_gate_still_blocks_this_template(): void
    {
        $this->seedAgency();
        $this->runSeeder();
        [$contact, , $agent] = $this->seedWorld();

        // No property to name — the message would promise to market "your property at ".
        $ctx = app(SellerOutreachComposerService::class)->composeContext(
            agencyId: self::AGENCY_ID, contact: $contact, property: null,
            channel: 'email', templateId: $this->template('email')->id, agent: $agent,
        );

        $this->assertArrayHasKey('no_address', $ctx->validationIssues);
        $this->assertFalse($ctx->isSendable());
    }

    /** Re-running the seeder edits in place — it never duplicates the library. */
    public function test_the_seeder_is_idempotent(): void
    {
        $this->seedAgency();
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(2, SellerOutreachTemplate::withoutGlobalScopes()
            ->where('name', self::NAME)->count(), 'exactly one row per channel');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function assertNoUnresolvedTokens(string $body): void
    {
        // {opt_out_link} is deliberately still a token here — the SENDER substitutes
        // the real per-send URL at dispatch. Everything else must be resolved.
        $left = str_replace('{opt_out_link}', '', $body);
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_\/?]+\}/i', $left,
            'every merge field must resolve — no braces may reach a seller');
    }

    private function compose(string $channel, Contact $contact, ?Property $property, User $agent)
    {
        return app(SellerOutreachComposerService::class)->composeContext(
            agencyId: self::AGENCY_ID, contact: $contact, property: $property,
            channel: $channel, templateId: $this->template($channel)->id, agent: $agent,
        );
    }

    private function template(string $channel): ?SellerOutreachTemplate
    {
        return SellerOutreachTemplate::withoutGlobalScopes()
            ->where('agency_id', self::AGENCY_ID)
            ->where('channel', $channel)
            ->where('name', self::NAME)
            ->first();
    }

    private function runSeeder(): void
    {
        (new HfcConsentTemplatesSeeder())->run();
    }

    private function seedAgency(): void
    {
        DB::table('agencies')->insert([
            'id' => self::AGENCY_ID, 'name' => 'Home Finders Coastal', 'slug' => 'hfc',
            'phone' => '039 315 0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => self::AGENCY_ID, 'agency_id' => self::AGENCY_ID, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:Contact,1:Property,2:User} */
    private function seedWorld(): array
    {
        $contact = Contact::create([
            'agency_id' => self::AGENCY_ID, 'branch_id' => self::AGENCY_ID,
            'first_name' => 'Thandi', 'last_name' => 'Mkhize',
            'phone' => '+27821234567', 'email' => 'thandi-' . Str::random(5) . '@example.test',
        ]);

        $ownerId = User::factory()->create([
            'agency_id' => self::AGENCY_ID, 'branch_id' => self::AGENCY_ID, 'role' => 'agent',
        ])->id;

        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'AT263-' . Str::random(8), 'title' => '1 Alamien Avenue',
            'address' => '1 Alamien Avenue, Uvongo', 'street_number' => '1',
            'street_name' => 'Alamien Avenue', 'suburb' => 'Uvongo',
            'price' => 2_100_000, 'property_type' => 'house', 'beds' => 3,
            'status' => 'active', 'is_demo' => false,
            'agency_id' => self::AGENCY_ID, 'branch_id' => self::AGENCY_ID, 'agent_id' => $ownerId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            $contact,
            Property::withoutGlobalScopes()->findOrFail($propertyId),
            $this->seedAgent(ffc: 'FFC-2026-4471'),
        ];
    }

    private function seedAgent(?string $ffc): User
    {
        return User::factory()->create([
            'agency_id' => self::AGENCY_ID, 'branch_id' => self::AGENCY_ID, 'role' => 'agent',
            'name' => 'Nomsa Dlamini',
            'designation' => 'property_practitioner',
            'ffc_number' => $ffc, 'phone' => '+27821110000',
        ]);
    }
}
