<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryBuyerAcceptance;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventorySignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FindsOrCreatesDocumentTypes;
use Tests\TestCase;

/**
 * §24 ruling (Johan, 2026-09-29) — buyer acceptance: a genuinely separate
 * record from RentalInventorySignature that NEVER reopens or edits the
 * completed inventory, offered ONLY once a committed (Granted/Registered)
 * deal exists on the property, captured via a token-gated public page
 * (on-screen or wet-ink — no CoreX login for the buyer).
 *
 * MAIL SAFETY — same discipline as RentalInventoryDistributionTest: no
 * agent/user fixture here carries a CommunicationMailbox row, so the send
 * path always falls through to Laravel's default mailer, which Mail::fake()
 * intercepts completely.
 */
final class RentalInventoryBuyerAcceptanceTest extends TestCase
{
    use FindsOrCreatesDocumentTypes;
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Buyer Acceptance Agency', 'slug' => 'buyer-acc-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($this->agent);

        $this->documentTypeId('inventory_list', 'Inventory List');
        Storage::fake('public');
        Mail::fake();
        // Outside production the distribution service suppresses every send unless a redirect address
        // is configured (see SignedDocumentDistributionService) — set one so the agent "send" path runs.
        config(['mail.non_production_redirect' => 'test-redirect@example.test']);
    }

    /** A completed sale inventory, no committed deal yet. */
    private function completedSaleInventory(): array
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Buyer Acceptance Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $seller = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Seller', 'email' => 'ba-seller-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
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
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_SELLER, 'signed', [
            'party_contact_id' => $seller->id, 'party_signature_path' => 'signatures/fake-seller.png',
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_AGENT, 'signed', [
            'party_signature_path' => 'signatures/fake.png',
        ]);
        $inventory->markCompleted();

        return [$property, $inventory->fresh(['property'])];
    }

    private function grantDeal(Property $property, Contact $buyer): Deal
    {
        $deal = Deal::create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'property_id' => $property->id,
            'deal_no' => random_int(100000, 999999),
            'deal_date' => now()->toDateString(),
            'period' => now()->format('Y-m'),
            'property_value' => 1850000,
            'total_commission' => 115000,
            'accepted_status' => 'G',
            'commission_status' => 'Not Paid',
        ]);
        $deal->contacts()->attach($buyer->id, ['role' => 'buyer']);

        return $deal->fresh();
    }

    public function test_buyer_acceptance_not_offered_without_a_committed_deal(): void
    {
        [, $inventory] = $this->completedSaleInventory();

        self::assertFalse($inventory->buyerAcceptanceOfferedFor());
        self::assertCount(0, $inventory->eligibleBuyerContacts());
    }

    public function test_buyer_acceptance_offered_once_deal_is_committed(): void
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $buyer = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Bongani', 'last_name' => 'Buyer',
            'email' => 'ba-buyer-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->grantDeal($property, $buyer);

        $inventory = $inventory->fresh(['property']);
        self::assertTrue($inventory->buyerAcceptanceOfferedFor());
        self::assertTrue($inventory->eligibleBuyerContacts()->contains(fn (Contact $c) => (int) $c->id === (int) $buyer->id));
        self::assertCount(1, $inventory->outstandingBuyerAcceptances());
    }

    public function test_buyer_can_capture_an_on_screen_acceptance_without_reopening_the_inventory(): void
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $buyer = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Zanele', 'last_name' => 'Buyer',
            'email' => 'ba-buyer2-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->grantDeal($property, $buyer);
        $inventory = $inventory->fresh(['property']);

        $acceptance = RentalInventoryBuyerAcceptance::capture($inventory, $buyer, RentalInventoryBuyerAcceptance::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/buyer-sig.png',
        ]);

        self::assertSame('signed', $acceptance->disposition);
        self::assertSame(RentalInventory::STATUS_COMPLETED, $inventory->fresh()->status, 'Capturing a buyer acceptance must never reopen the completed inventory.');
        self::assertCount(0, $inventory->fresh(['property'])->outstandingBuyerAcceptances());
    }

    public function test_buyer_acceptance_refuses_a_non_eligible_contact(): void
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $buyer = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Real', 'last_name' => 'Buyer',
            'email' => 'ba-real-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->grantDeal($property, $buyer);
        $inventory = $inventory->fresh(['property']);

        $randomContact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Not', 'last_name' => 'ABuyer',
            'email' => 'not-a-buyer-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        RentalInventoryBuyerAcceptance::capture($inventory, $randomContact, RentalInventoryBuyerAcceptance::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/sig.png',
        ]);
    }

    public function test_buyer_acceptance_refuses_a_second_capture_for_the_same_buyer(): void
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $buyer = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Once', 'last_name' => 'Only',
            'email' => 'ba-once-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->grantDeal($property, $buyer);
        $inventory = $inventory->fresh(['property']);

        RentalInventoryBuyerAcceptance::capture($inventory, $buyer, RentalInventoryBuyerAcceptance::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/sig.png',
        ]);

        $this->expectException(\LogicException::class);
        RentalInventoryBuyerAcceptance::capture($inventory, $buyer, RentalInventoryBuyerAcceptance::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/sig2.png',
        ]);
    }

    public function test_public_endpoint_captures_a_buyers_on_screen_acceptance(): void
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $buyer = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Public', 'last_name' => 'Signer',
            'email' => 'ba-public-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->grantDeal($property, $buyer);
        $inventory = $inventory->fresh(['property']);
        $inventory->generatePublicLink();

        \Illuminate\Support\Facades\Auth::logout();

        $response = $this->postJson(route('rental-inventories.public.buyer-acceptance.store', $inventory->fresh()->public_token), [
            'buyer_contact_id' => $buyer->id,
            'disposition' => 'signed',
            'signature_image' => 'data:image/png;base64,' . base64_encode('fake-image-bytes'),
        ]);

        $response->assertStatus(201);
        self::assertSame(1, RentalInventoryBuyerAcceptance::where('rental_inventory_id', $inventory->id)->where('buyer_contact_id', $buyer->id)->count());
        self::assertSame(RentalInventory::STATUS_COMPLETED, $inventory->fresh()->status);
    }

    public function test_public_endpoint_captures_a_wet_ink_upload(): void
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $buyer = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Wet', 'last_name' => 'Ink',
            'email' => 'ba-wetink-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->grantDeal($property, $buyer);
        $inventory = $inventory->fresh(['property']);
        $inventory->generatePublicLink();

        \Illuminate\Support\Facades\Auth::logout();

        $response = $this->post(route('rental-inventories.public.buyer-acceptance.store', $inventory->fresh()->public_token), [
            'buyer_contact_id' => $buyer->id,
            'disposition' => 'wet_ink',
            'wet_ink_file' => UploadedFile::fake()->create('acceptance.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $acceptance = RentalInventoryBuyerAcceptance::where('rental_inventory_id', $inventory->id)->first();
        self::assertSame('wet_ink', $acceptance->disposition);
        self::assertNotNull($acceptance->wet_ink_upload_path);
    }

    public function test_public_endpoint_refuses_an_invalid_token(): void
    {
        \Illuminate\Support\Facades\Auth::logout();

        $this->postJson(route('rental-inventories.public.buyer-acceptance.store', 'not-a-real-token'), [
            'buyer_contact_id' => 1,
            'disposition' => 'signed',
            'signature_image' => 'data:image/png;base64,' . base64_encode('x'),
        ])->assertStatus(404);
    }

    public function test_agent_send_endpoint_emails_the_buyer_their_link(): void
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $buyer = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Email', 'last_name' => 'Buyer',
            'email' => 'ba-email-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->grantDeal($property, $buyer);
        $inventory = $inventory->fresh(['property']);

        $response = $this->postJson(route('corex.rental-inventories.buyer-acceptances.send', [$inventory, $buyer]));

        $response->assertStatus(200)->assertJsonPath('status', 'sent');
        Mail::assertSent(SignedDocumentDistributionMail::class);
        self::assertTrue($inventory->fresh()->hasValidPublicLink(), 'Sending must generate a public link if none exists yet.');
    }

    public function test_agent_send_endpoint_refuses_when_not_offered(): void
    {
        [, $inventory] = $this->completedSaleInventory();
        $randomContact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'No', 'last_name' => 'Deal',
            'email' => 'ba-nodeal-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.buyer-acceptances.send', [$inventory, $randomContact]))
            ->assertStatus(409);
        Mail::assertNothingSent();
    }
}
