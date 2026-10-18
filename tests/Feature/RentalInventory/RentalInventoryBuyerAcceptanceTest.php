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
        Storage::fake('local');
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
            'buyer_token' => $inventory->fresh()->buyerAcceptanceTokenFor($buyer),
            'disposition' => 'signed',
            'signature_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
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
            'buyer_token' => $inventory->fresh()->buyerAcceptanceTokenFor($buyer),
            'disposition' => 'wet_ink',
            'wet_ink_file' => UploadedFile::fake()->create('acceptance.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $acceptance = RentalInventoryBuyerAcceptance::where('rental_inventory_id', $inventory->id)->first();
        self::assertSame('wet_ink', $acceptance->disposition);
        self::assertNotNull($acceptance->wet_ink_upload_path);
        self::assertStringStartsWith('private:', $acceptance->wet_ink_upload_path, 'the scan must be stored on the private disk');
    }

    public function test_public_endpoint_refuses_an_invalid_token(): void
    {
        \Illuminate\Support\Facades\Auth::logout();

        $this->postJson(route('rental-inventories.public.buyer-acceptance.store', 'not-a-real-token'), [
            'buyer_token' => '1.' . str_repeat('a', 64),
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

    // ── Audit fixes 2026-10-18 (A-H1, A-H2, A-M3, A-M4, A-M5) ────────────

    /** A completed sale inventory with one committed buyer, a live public link, and a second user who can see only their own records. */
    private function sharedLinkFixture(): array
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $buyer = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Tok', 'last_name' => 'Buyer',
            'email' => 'ba-tok-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->grantDeal($property, $buyer);
        $inventory = $inventory->fresh(['property']);
        $inventory->generatePublicLink();

        return [$property, $inventory->fresh(['property']), $buyer];
    }

    private function colleague(): User
    {
        // Same agency AND branch, default agent role = own scope: cannot see $this->agent's inventory.
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    public function test_the_shared_link_alone_cannot_record_a_buyer_acceptance(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        \Illuminate\Support\Facades\Auth::logout();
        $url = route('rental-inventories.public.buyer-acceptance.store', $inventory->public_token);
        $sig = 'data:image/png;base64,' . base64_encode('x');

        // The old way: buyer id in the body, shared token only.
        $this->postJson($url, ['buyer_contact_id' => $buyer->id, 'disposition' => 'signed', 'signature_image' => $sig])->assertStatus(404);
        // A forged buyer token (right shape, wrong HMAC).
        $this->postJson($url, ['buyer_token' => $buyer->id . '.' . str_repeat('0', 64), 'disposition' => 'signed', 'signature_image' => $sig])->assertStatus(404);

        self::assertSame(0, RentalInventoryBuyerAcceptance::where('rental_inventory_id', $inventory->id)->count());
    }

    public function test_the_buyer_comes_from_the_token_not_the_request_body(): void
    {
        [$property, $inventory, $buyer] = $this->sharedLinkFixture();
        $other = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Other', 'last_name' => 'Buyer',
            'email' => 'ba-other-' . uniqid() . '@example.test', 'created_by_user_id' => $this->agent->id,
        ]);
        Deal::query()->where('property_id', $property->id)->first()->contacts()->attach($other->id, ['role' => 'buyer']);
        \Illuminate\Support\Facades\Auth::logout();

        $this->postJson(route('rental-inventories.public.buyer-acceptance.store', $inventory->public_token), [
            'buyer_token' => $inventory->buyerAcceptanceTokenFor($buyer),
            'buyer_contact_id' => $other->id, // ignored
            'disposition' => 'signed',
            'signature_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ])->assertStatus(201);

        self::assertSame([$buyer->id], RentalInventoryBuyerAcceptance::where('rental_inventory_id', $inventory->id)->pluck('buyer_contact_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_failure_responses_do_not_reveal_which_contact_ids_exist(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        \Illuminate\Support\Facades\Auth::logout();
        $url = route('rental-inventories.public.buyer-acceptance.store', $inventory->public_token);
        $payload = fn (string $tok) => ['buyer_token' => $tok, 'disposition' => 'signed', 'signature_image' => 'data:image/png;base64,' . base64_encode('x')];

        $realId = $this->postJson($url, $payload($buyer->id . '.' . str_repeat('1', 64)));
        $missingId = $this->postJson($url, $payload('99999999.' . str_repeat('1', 64)));

        self::assertSame($realId->getStatusCode(), $missingId->getStatusCode());
        self::assertSame($realId->getContent(), $missingId->getContent());
    }

    public function test_a_buyer_token_stops_working_when_the_public_link_is_regenerated(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        $oldToken = $inventory->buyerAcceptanceTokenFor($buyer);
        $inventory->generatePublicLink();
        \Illuminate\Support\Facades\Auth::logout();

        $this->postJson(route('rental-inventories.public.buyer-acceptance.store', $inventory->fresh()->public_token), [
            'buyer_token' => $oldToken, 'disposition' => 'signed', 'signature_image' => 'data:image/png;base64,' . base64_encode('x'),
        ])->assertStatus(404);
    }

    public function test_the_public_page_shows_a_signing_block_only_for_the_buyer_whose_personal_link_it_is(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        \Illuminate\Support\Facades\Auth::logout();

        $this->get(route('rental-inventories.public.show', $inventory->public_token))
            ->assertOk()->assertDontSee('id="buyer-acceptance"', false);

        $this->get($inventory->buyerAcceptanceUrlFor($buyer))
            ->assertOk()->assertSee('id="buyer-acceptance"', false)->assertSee('Tok Buyer');

        $this->get(route('rental-inventories.public.show', ['token' => $inventory->public_token, 'buyer' => '1.' . str_repeat('f', 64)]))
            ->assertOk()->assertDontSee('id="buyer-acceptance"', false);
    }

    public function test_the_emailed_link_is_the_buyers_personal_link(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();

        $this->postJson(route('corex.rental-inventories.buyer-acceptances.send', [$inventory, $buyer]))->assertStatus(200);

        Mail::assertSent(SignedDocumentDistributionMail::class, function (SignedDocumentDistributionMail $mail) use ($inventory, $buyer) {
            return $mail->publicUrl === $inventory->fresh()->buyerAcceptanceUrlFor($buyer)
                && str_contains((string) $mail->publicUrl, 'buyer=' . $buyer->id . '.');
        });
    }

    public function test_send_is_refused_for_a_colleagues_inventory_and_sends_and_changes_nothing(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        $before = $inventory->public_token;
        $colleague = $this->colleague();
        // The colleague may see the BUYER contact (their own record) — only the inventory is out of scope,
        // so this proves the inventory check itself and not the contact binding.
        $buyer->forceFill(['created_by_user_id' => $colleague->id])->save();
        $this->actingAs($colleague);

        $this->postJson(route('corex.rental-inventories.buyer-acceptances.send', [$inventory, $buyer]))->assertStatus(404);

        Mail::assertNothingSent();
        self::assertSame($before, $inventory->fresh()->public_token);
    }

    public function test_print_for_signature_is_refused_for_a_colleagues_inventory(): void
    {
        [$property, $inventory] = $this->completedSaleInventory();
        $this->actingAs($this->colleague());

        $this->get(route('corex.rental-inventories.print-for-signature', $inventory))->assertStatus(404);
        self::assertNull($inventory->fresh()->public_token, 'a refused request must not mint a link either');
    }

    public function test_print_for_signature_no_longer_creates_a_public_link(): void
    {
        [, $inventory] = $this->completedSaleInventory();
        self::assertNull($inventory->fresh()->public_token);

        $this->get(route('corex.rental-inventories.print-for-signature', $inventory))->assertOk();

        self::assertNull($inventory->fresh()->public_token, 'a GET under view permission must not write a public link');
    }

    public function test_supersede_wet_ink_is_refused_for_a_colleagues_inventory(): void
    {
        [, $inventory] = $this->completedSaleInventory();
        $signature = $inventory->signatures()->first();
        $this->actingAs($this->colleague());

        $this->postJson(route('corex.rental-inventories.signatures.supersede-wet-ink', [$inventory, $signature]), [
            'wet_ink_file' => UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf'),
        ])->assertStatus(404);
    }

    public function test_wet_ink_scan_is_stored_privately_with_a_server_chosen_extension(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        \Illuminate\Support\Facades\Auth::logout();

        $this->post(route('rental-inventories.public.buyer-acceptance.store', $inventory->public_token), [
            'buyer_token' => $inventory->buyerAcceptanceTokenFor($buyer),
            'disposition' => 'wet_ink',
            'wet_ink_file' => UploadedFile::fake()->create('evil.html', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $stored = RentalInventoryBuyerAcceptance::where('rental_inventory_id', $inventory->id)->value('wet_ink_upload_path');
        self::assertStringStartsWith('private:rental-inventory-buyer-acceptances/', $stored);
        self::assertStringEndsWith('.pdf', $stored, 'the client-supplied .html extension must never be used');
        Storage::disk('local')->assertExists(substr($stored, strlen('private:')));
        self::assertSame([], Storage::disk('public')->allFiles(), 'nothing may land on the public disk');
    }

    public function test_an_ineligible_attempt_writes_no_file(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        RentalInventoryBuyerAcceptance::capture($inventory, $buyer, 'signed', ['party_signature_path' => '/fake/first.png']);
        \Illuminate\Support\Facades\Auth::logout();

        $this->post(route('rental-inventories.public.buyer-acceptance.store', $inventory->public_token), [
            'buyer_token' => $inventory->buyerAcceptanceTokenFor($buyer),
            'disposition' => 'wet_ink',
            'wet_ink_file' => UploadedFile::fake()->create('again.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        self::assertSame([], Storage::disk('local')->allFiles());
        self::assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_oversized_signature_image_is_refused(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        \Illuminate\Support\Facades\Auth::logout();

        $this->postJson(route('rental-inventories.public.buyer-acceptance.store', $inventory->public_token), [
            'buyer_token' => $inventory->buyerAcceptanceTokenFor($buyer),
            'disposition' => 'signed',
            'signature_image' => 'data:image/png;base64,' . str_repeat('A', 1500000),
        ])->assertStatus(422);

        self::assertSame(0, RentalInventoryBuyerAcceptance::where('rental_inventory_id', $inventory->id)->count());
    }

    public function test_the_agent_can_view_the_private_scan_through_the_checked_route_and_a_colleague_cannot(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        $path = 'rental-inventory-buyer-acceptances/' . $inventory->property_id . '/wetink_test.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $acceptance = RentalInventoryBuyerAcceptance::capture($inventory, $buyer, 'wet_ink', ['wet_ink_upload_path' => 'private:' . $path]);

        $url = $acceptance->wetInkFileUrl();
        self::assertStringContainsString('/wet-ink-file', $url);

        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->colleague());
        $this->get($url)->assertStatus(404);
    }

    public function test_a_legacy_public_scan_value_is_still_linked_as_is(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        $acceptance = RentalInventoryBuyerAcceptance::capture($inventory, $buyer, 'wet_ink', ['wet_ink_upload_path' => '/storage/properties/1/rental-inventory-buyer-acceptances/old.pdf']);

        self::assertSame('/storage/properties/1/rental-inventory-buyer-acceptances/old.pdf', $acceptance->wetInkFileUrl());
    }

    // ── Follow-up: buyer canvas signature must really be a PNG ────────────

    public function test_a_buyer_canvas_signature_that_is_not_a_png_is_refused_and_writes_nothing(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        \Illuminate\Support\Facades\Auth::logout();

        // HTML labelled as a PNG data URI — what used to be stored as-is on the public disk.
        $this->postJson(route('rental-inventories.public.buyer-acceptance.store', $inventory->public_token), [
            'buyer_token' => $inventory->buyerAcceptanceTokenFor($buyer),
            'disposition' => 'signed',
            'signature_image' => 'data:image/png;base64,' . base64_encode('<html><script>alert(1)</script></html>'),
        ])->assertStatus(422);

        self::assertSame(0, RentalInventoryBuyerAcceptance::where('rental_inventory_id', $inventory->id)->count());
        self::assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_real_buyer_canvas_signature_is_stored_as_a_png(): void
    {
        [, $inventory, $buyer] = $this->sharedLinkFixture();
        \Illuminate\Support\Facades\Auth::logout();

        $this->postJson(route('rental-inventories.public.buyer-acceptance.store', $inventory->public_token), [
            'buyer_token' => $inventory->buyerAcceptanceTokenFor($buyer),
            'disposition' => 'signed',
            'signature_image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ])->assertStatus(201);

        $files = Storage::disk('public')->allFiles();
        self::assertCount(1, $files);
        self::assertStringEndsWith('.png', $files[0]);
        self::assertStringStartsWith("\x89PNG", Storage::disk('public')->get($files[0]));
    }
}
