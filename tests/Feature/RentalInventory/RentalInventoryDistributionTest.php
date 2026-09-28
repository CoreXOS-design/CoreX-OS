<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventorySetting;
use App\Models\RentalInventorySignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * §41-follow-up (Job 3, 2026-09-28) — Inventory wired to the SHARED
 * App\Services\Distribution\SignedDocumentDistributionService, reusing
 * exactly what RentalInspection already established (see
 * .ai/specs/signed-document-distribution.md). Covers both inventory
 * shapes (lease-attached and property-level, §15) since
 * distributionRecipients() branches on lease_id.
 *
 * MAIL SAFETY, non-negotiable (conductor's explicit instruction): no test
 * here may ever open a connection to a real SMTP host. Every agent/user
 * fixture in this file deliberately has NO App\Models\Communications\
 * CommunicationMailbox row, so SignedDocumentDistributionService's own
 * send path (BaseSignatureMail::resolvedMailbox()) always resolves null
 * and falls through to Laravel's DEFAULT mailer — which Mail::fake()
 * intercepts completely, so no real network connection is ever made
 * regardless of environment. This is deliberately NOT testing
 * PerMailboxMailTransportBuilder's own real-SMTP path at all — that path
 * still points at agencies' real mailboxes (e.g. mail.hfcoastal.co.za)
 * until cc3's Mailpit-redirect fix lands on origin/QA1; it must not be
 * exercised from an automated test before then.
 */
final class RentalInventoryDistributionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Distribution Test Agency', 'slug' => 'dist-test-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        // Deliberately NO CommunicationMailbox row for this agent — see the
        // class docblock above for why that is the whole safety mechanism.
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);
    }

    /** Everything up to (not including) completion — every test decides how to complete it. */
    private function readyToCompletePropertyLevelInventory(): RentalInventory
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Distribution Sale Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $seller = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Sally', 'last_name' => 'Seller',
            'email' => 'seller@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $property->contacts()->attach($seller->id, ['role' => 'seller']);

        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id,
            'type' => 'kitchen', 'label' => 'Kitchen', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        $inventory = RentalInventory::startForProperty($property, $this->agent);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $room->id, 'room_label' => $room->label,
            'quantity' => 1, 'description' => 'Built-in oven', 'created_by_user_id' => $this->agent->id,
        ]);
        // The attached seller resolves as this property's sellerOwnerContact(),
        // so markCompleted()'s own gate requires their disposition too — same
        // 'landlord' party_role RentalInventorySignature stores either way
        // (§15's own note: display-only distinction, not a schema one).
        // Must be captured BEFORE the agent's own signature — capture()'s
        // agent branch refuses while any party is still outstanding.
        RentalInventorySignature::capture($inventory, 'landlord', 'signed', [
            'party_contact_id' => $seller->id,
            'party_signature_path' => 'signatures/fake-seller.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($inventory, 'agent', 'signed', [
            'party_signature_path' => 'signatures/fake.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);

        return $inventory->fresh(['property', 'lease']);
    }

    private function completedPropertyLevelInventory(): RentalInventory
    {
        $inventory = $this->readyToCompletePropertyLevelInventory();
        $inventory->markCompleted();

        return $inventory->fresh(['property', 'lease']);
    }

    public function test_property_level_inventory_recipients_are_seller_and_agent_only(): void
    {
        $inventory = $this->completedPropertyLevelInventory();

        $recipients = $inventory->distributionRecipients();

        $this->assertCount(1, $recipients);
        $this->assertSame('seller', $recipients[0]['role']);
        $this->assertSame('seller@example.test', $recipients[0]['email']);
    }

    public function test_lease_attached_inventory_recipients_are_tenant_and_landlord(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Distribution Rental Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Larry', 'last_name' => 'Landlord',
            'email' => 'landlord@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $property->contacts()->attach($landlord->id, ['role' => 'landlord']);
        $tenantContact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Tammy', 'last_name' => 'Tenant',
            'email' => 'tenant@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenantContact->id]);

        $inventory = RentalInventory::start($property, $lease, $this->agent);
        $recipients = $inventory->fresh(['lease.tenants.contact', 'property'])->distributionRecipients();

        $this->assertCount(2, $recipients);
        $this->assertSame('tenant', $recipients[0]['role']);
        $this->assertSame('tenant@example.test', $recipients[0]['email']);
        $this->assertSame('landlord', $recipients[1]['role']);
        $this->assertSame('landlord@example.test', $recipients[1]['email']);
    }

    public function test_completing_an_inventory_files_the_report_and_auto_emails_by_default(): void
    {
        Mail::fake();

        $inventory = $this->readyToCompletePropertyLevelInventory();

        $this->postJson(route('corex.rental-inventories.complete', $inventory))->assertOk();

        $document = Document::where('source_type', 'rental_inventory_report')->where('source_id', $inventory->id)->first();
        $this->assertNotNull($document, 'the signed report must be filed to the property');

        Mail::assertSent(SignedDocumentDistributionMail::class, function ($mail) {
            return $mail->documentLabel === 'Inventory report';
        });
    }

    public function test_auto_send_disabled_still_files_but_does_not_email(): void
    {
        Mail::fake();

        RentalInventorySetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['auto_send_report_enabled' => false],
        );

        $inventory = $this->readyToCompletePropertyLevelInventory();

        $this->postJson(route('corex.rental-inventories.complete', $inventory))->assertOk();

        $document = Document::where('source_type', 'rental_inventory_report')->where('source_id', $inventory->id)->first();
        $this->assertNotNull($document, 'filing must never be gated on the auto-send setting');

        Mail::assertNothingSent();
    }

    public function test_resend_report_endpoint_always_sends_regardless_of_the_auto_send_setting(): void
    {
        Mail::fake();

        RentalInventorySetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['auto_send_report_enabled' => false],
        );

        $inventory = $this->completedPropertyLevelInventory();

        $response = $this->postJson(route('corex.rental-inventories.resend-report', $inventory))->assertOk();

        $results = $response->json('results');
        $this->assertNotEmpty($results, 'a manual resend must send even when auto-send is off');
        $this->assertSame('sent', $results[0]['status']);

        Mail::assertSent(SignedDocumentDistributionMail::class);
    }

    public function test_resend_report_refuses_a_not_yet_completed_inventory(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Draft Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $inventory = RentalInventory::startForProperty($property, $this->agent);

        $this->postJson(route('corex.rental-inventories.resend-report', $inventory))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This inventory is not yet completed.');
    }

    public function test_public_share_page_renders_for_a_valid_token_and_generic_unavailable_for_an_invalid_one(): void
    {
        $inventory = $this->completedPropertyLevelInventory();
        $inventory->generatePublicLink();

        $this->get(route('rental-inventories.public.show', $inventory->fresh()->public_token))
            ->assertOk()
            ->assertSee('Inventory report', false)
            ->assertSee('Built-in oven', false);

        $this->get(route('rental-inventories.public.show', 'not-a-real-token'))
            ->assertOk()
            ->assertSee("This link isn't available", false);
    }

    public function test_generate_public_link_overwrites_and_revokes_the_previous_token(): void
    {
        $inventory = $this->completedPropertyLevelInventory();

        $first = $inventory->generatePublicLink();
        $this->assertTrue($inventory->fresh()->publicLinkIsValid());

        $second = $inventory->fresh()->generatePublicLink();
        $this->assertNotSame($first, $second);
        $this->assertNull(RentalInventory::findByPublicToken($first), 'regenerating must invalidate the old token immediately');
        $this->assertNotNull(RentalInventory::findByPublicToken($second));
    }

    public function test_settings_page_auto_send_toggle_requires_the_distinguishing_field_name(): void
    {
        // The dedicated settings form's own field name, deliberately NOT
        // the bare 'auto_send_report_enabled' Inspections uses — see
        // RentalInventorySettingsController::updateAutoSendReportEnabled()'s
        // own docblock for why (wizard-step key collision).
        $this->post(route('corex.settings.rental-inventory.auto-send-report'), [
            'inventory_auto_send_report_enabled' => '0',
        ])->assertRedirect();

        $this->assertFalse(RentalInventorySetting::autoSendReportEnabledFor($this->agency->id));

        // Omitting the field entirely (as if the checkbox's own hidden
        // fallback were missing) must be rejected, not silently ignored.
        $this->post(route('corex.settings.rental-inventory.auto-send-report'), [])
            ->assertSessionHasErrors('inventory_auto_send_report_enabled');
    }
}
