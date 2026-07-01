<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-142 — PPRA designation send-gate + surname-greeting hard block.
 *
 * The consent templates print the agent's admin-managed PPRA designation
 * (users.designation) via {agent_designation}, so the message is always
 * TRUTHFUL. The composer gates the send:
 *   - blank designation → BLOCK (no_designation) — never a blank statement.
 *   - agency toggle restrict_consent_outreach_to_full_status ON → only
 *     full-status/principal may send (designation_not_full_status); OFF (default)
 *     → any non-blank designation sends truthfully.
 * A template WITHOUT {agent_designation} is unaffected by these two guards.
 *
 * Also locks {seller_surname}: surname → first name → BLOCK (no "there").
 */
final class PpraStatusSendGateTest extends TestCase
{
    use RefreshDatabase;

    /** Prints the designation (templates A/B/C/E). include_tracking_link=false. */
    private const DESIGNATION_BODY = <<<'TXT'
Good day, {seller_surname}.

My name is {agent_name}, a {agent_designation} (PPRA registered) with {agency_name}. I'm reaching out regarding your property at {property_address}.

Reply OPT IN, or OPT OUT. Manage your preferences: {opt_out_link}
TXT;

    /** No designation statement (template D). */
    private const NO_DESIGNATION_BODY = <<<'TXT'
Good day, {seller_surname}.

{agent_name} here, from {agency_name} (PPRA registered). I'm reaching out regarding your property at {property_address}.

Reply OPT IN, or OPT OUT. Manage your preferences: {opt_out_link}
TXT;

    public function test_full_status_designation_resolves_and_sends(): void
    {
        [$agencyId, $contact, $property] = $this->seedWorld();
        $agent = $this->seedAgent($agencyId, 'Property Practitioner');
        $tid = $this->seedTemplate($agencyId, self::DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertStringContainsString('a Property Practitioner (PPRA registered)', $ctx->renderedBody);
        $this->assertArrayNotHasKey('no_designation', $ctx->validationIssues);
        $this->assertArrayNotHasKey('designation_not_full_status', $ctx->validationIssues);
        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
    }

    public function test_principal_designation_resolves_and_sends(): void
    {
        [$agencyId, $contact, $property] = $this->seedWorld();
        $agent = $this->seedAgent($agencyId, 'Principal Practitioner');
        $tid = $this->seedTemplate($agencyId, self::DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertStringContainsString('a Principal Practitioner (PPRA registered)', $ctx->renderedBody);
        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
    }

    public function test_candidate_renders_truthfully_and_sends_when_toggle_off(): void
    {
        // Default (toggle off): a candidate sends, and the message states their
        // real designation — truthful, not a false "Full Status" claim.
        [$agencyId, $contact, $property] = $this->seedWorld();
        $agent = $this->seedAgent($agencyId, 'Candidate Property Practitioner');
        $tid = $this->seedTemplate($agencyId, self::DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertStringContainsString('a Candidate Property Practitioner (PPRA registered)', $ctx->renderedBody);
        $this->assertArrayNotHasKey('designation_not_full_status', $ctx->validationIssues);
        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
    }

    public function test_candidate_is_BLOCKED_when_toggle_on(): void
    {
        [$agencyId, $contact, $property] = $this->seedWorld();
        $this->setRestrictToFullStatus($agencyId, true);
        $agent = $this->seedAgent($agencyId, 'Candidate Property Practitioner');
        $tid = $this->seedTemplate($agencyId, self::DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertArrayHasKey('designation_not_full_status', $ctx->validationIssues);
        $this->assertFalse($ctx->isSendable());
    }

    public function test_principal_still_sends_when_toggle_on(): void
    {
        [$agencyId, $contact, $property] = $this->seedWorld();
        $this->setRestrictToFullStatus($agencyId, true);
        $agent = $this->seedAgent($agencyId, 'Principal Practitioner');
        $tid = $this->seedTemplate($agencyId, self::DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertArrayNotHasKey('designation_not_full_status', $ctx->validationIssues);
        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
    }

    public function test_blank_designation_is_BLOCKED(): void
    {
        // Never send a designation statement with an empty designation.
        [$agencyId, $contact, $property] = $this->seedWorld();
        $agent = $this->seedAgent($agencyId, null);
        $tid = $this->seedTemplate($agencyId, self::DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertArrayHasKey('no_designation', $ctx->validationIssues);
        $this->assertFalse($ctx->isSendable());
    }

    public function test_template_without_designation_token_unaffected_by_blank_designation(): void
    {
        // Template D (no {agent_designation}) makes no designation claim → a
        // blank-designation agent can still send it.
        [$agencyId, $contact, $property] = $this->seedWorld();
        $agent = $this->seedAgent($agencyId, null);
        $tid = $this->seedTemplate($agencyId, self::NO_DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertArrayNotHasKey('no_designation', $ctx->validationIssues);
        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
    }

    public function test_nameless_contact_is_blocked_no_blank_greeting(): void
    {
        [$agencyId, $contact, $property] = $this->seedWorld(nameless: true);
        $agent = $this->seedAgent($agencyId, 'Property Practitioner');
        $tid = $this->seedTemplate($agencyId, self::NO_DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertArrayHasKey('no_recipient_name', $ctx->validationIssues);
        $this->assertFalse($ctx->isSendable());
    }

    public function test_first_name_only_contact_still_greets_and_sends(): void
    {
        [$agencyId, $contact, $property] = $this->seedWorld(firstNameOnly: true);
        $agent = $this->seedAgent($agencyId, 'Property Practitioner');
        $tid = $this->seedTemplate($agencyId, self::NO_DESIGNATION_BODY);

        $ctx = $this->compose($agencyId, $contact, $property, $agent, $tid);

        $this->assertArrayNotHasKey('no_recipient_name', $ctx->validationIssues);
        $this->assertStringContainsString('Good day, Sipho.', $ctx->renderedBody);
        $this->assertTrue($ctx->isSendable(), json_encode($ctx->validationIssues));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function compose(int $agencyId, Contact $contact, Property $property, User $agent, int $templateId)
    {
        return app(SellerOutreachComposerService::class)->composeContext(
            agencyId: $agencyId, contact: $contact, property: $property,
            channel: 'whatsapp', templateId: $templateId, agent: $agent,
        );
    }

    private function setRestrictToFullStatus(int $agencyId, bool $on): void
    {
        DB::table('agencies')->where('id', $agencyId)
            ->update(['restrict_consent_outreach_to_full_status' => $on]);
    }

    /** @return array{0:int,1:Contact,2:Property} */
    private function seedWorld(bool $nameless = false, bool $firstNameOnly = false): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = $nameless ? '' : 'Sipho';
        $last = ($nameless || $firstNameOnly) ? '' : 'Ndlovu';
        $contact = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => $first, 'last_name' => $last,
            'phone' => '+27821234567', 'email' => 'c-' . Str::random(6) . '@example.test',
        ]);

        // Listing-agent owner for the property's NOT-NULL agent_id (distinct from
        // the sending agent, which each test creates with a specific designation).
        $ownerId = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ])->id;

        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'TEST-' . Str::random(8), 'title' => '14 Marine Drive',
            'address' => '14 Marine Drive, Margate', 'street_number' => '14',
            'street_name' => 'Marine Drive', 'suburb' => 'Margate',
            'price' => 1_850_000, 'property_type' => 'house', 'beds' => 3,
            'status' => 'active', 'is_demo' => false,
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $ownerId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $contact, Property::withoutGlobalScopes()->findOrFail($propertyId)];
    }

    private function seedAgent(int $agencyId, ?string $designation): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
            'designation' => $designation, 'phone' => '+27821110000',
        ]);
    }

    private function seedTemplate(int $agencyId, string $body): int
    {
        return (int) DB::table('seller_outreach_templates')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'tpl-' . Str::random(6),
            'channel' => 'whatsapp', 'subject' => null, 'body' => $body,
            'description' => 'test', 'is_active' => true, 'is_default_for_channel' => false,
            'include_tracking_link' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
