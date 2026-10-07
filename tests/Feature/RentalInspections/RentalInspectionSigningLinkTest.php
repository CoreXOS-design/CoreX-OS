<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Mail\Rentals\RentalInspectionSigningLinkMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInspectionSigningLink;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalInspectionSigningLinkService;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §46 — signing an inspection by a personal link / QR code / on the agent's device.
 *
 * Paths covered: a link per party (each tenant, the landlord, the agent) · wrong / revoked / expired / replaced token ·
 * signed twice · decline with the agency's own reasons · not ready to sign · completed stays a read-only view ·
 * archived / cancelled · the setting off · ad-hoc · QR target equals the link · on-device signing audit · the email,
 * WhatsApp and copy channels and the status each leaves · opened tracking · scoping and permissions · the completion
 * trigger and the completion copies, unchanged.
 *
 * MAIL SAFETY: every send goes through Mail::fake(); the non-production redirect is the one permitted test address.
 */
final class RentalInspectionSigningLinkTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\RentalInspections\Concerns\RecordsAttendance;

    private const TEST_ADDRESS = 'can.assurance@gmail.com';
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    // Static text of rental-inspections/public/unavailable.blade.php, with no apostrophe so assertSee() cannot miss it.
    private const UNAVAILABLE = 'Please contact your agent for a current link.';

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $inspector;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $tenant2;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();
        config(['mail.non_production_redirect' => self::TEST_ADDRESS]);
        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        \App\Models\DocumentType::firstOrCreate(['slug' => 'inspection_report'], ['label' => 'Inspection Report', 'is_active' => true]);

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Sea Point', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Aileen Agent', 'email' => 'aileen@cape.test']);
        $this->inspector = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Ivan Inspector', 'email' => 'ivan@cape.test']);
        $this->actingAs($this->admin);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '14 Jackson Street', 'status' => 'active', 'listing_type' => 'rental',
            'suburb' => 'Sea Point', 'address' => '14 Jackson Street',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12500, 'start_date' => now()->subMonths(6),
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->tenant = $this->contact('Naledi', 'Dlamini', 'naledi@example.co.za');
        $this->tenant2 = $this->contact('Sipho', 'Khumalo', 'sipho@example.co.za');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant2->id]);
        $this->landlord = $this->contact('Pieter', 'van Wyk', 'pieter@example.co.za');
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
    }

    protected function tearDown(): void
    {
        User::getEventDispatcher()?->forget('eloquent.retrieved: ' . User::class);
        parent::tearDown();
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    private function contact(string $first, string $last, ?string $email): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'created_by_user_id' => $this->admin->id,
        ]);
    }

    /** An inspection with one graded item, ready to sign (so the report has real content), attendance recorded. */
    private function inspection(string $type = RentalInspection::TYPE_IN, bool $readyToSign = true): RentalInspection
    {
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->admin->id, 'inspector_user_id' => $this->inspector->id,
        ]);
        // One checklist item per property, graded on every inspection (a second inspection of the same property must
        // not find an ungraded item and refuse to start its signing window).
        $item = RentalInspectionItem::where('property_id', $this->property->id)->first()
            ?? RentalInspectionItem::create([
                'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Lounge Ceiling', 'created_by_user_id' => $this->admin->id,
            ]);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id, 'observed_by_user_id' => $this->admin->id,
            'condition' => 'good', 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);
        if ($readyToSign && $type !== RentalInspection::TYPE_AD_HOC) {
            $inspection->startAwaitingSignature();
            $this->recordAttendanceForEveryParty($inspection);
        }

        return $inspection->fresh();
    }

    private function links(): RentalInspectionSigningLinkService
    {
        return app(RentalInspectionSigningLinkService::class);
    }

    private function issue(RentalInspection $inspection, string $role, ?int $contactId = null): RentalInspectionSigningLink
    {
        return $this->links()->issue($inspection, $role, $contactId, $this->admin);
    }

    private function guest(): void
    {
        Auth::guard('web')->logout();
        $this->app['auth']->forgetGuards();
    }

    private function signPayload(array $over = []): array
    {
        return $over + ['action' => 'sign', 'typed_name' => 'Naledi Dlamini', 'signature_image' => self::PNG, 'read_confirmed' => true, 'comment' => null];
    }

    private function submit(RentalInspectionSigningLink $link, array $payload = [], array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson(route('rental-inspections.sign.submit', $link->token), $this->signPayload($payload), $headers);
    }

    private function row(array $panel, string $key): array
    {
        return collect($panel['rows'])->firstWhere('key', $key);
    }

    // ═══ A personal link per party ═════════════════════════════════════════════

    public function test_every_party_gets_a_personal_link_with_its_own_unguessable_token(): void
    {
        $inspection = $this->inspection();
        $tenant = $this->issue($inspection, 'tenant', $this->tenant->id);
        $tenant2 = $this->issue($inspection, 'tenant', $this->tenant2->id);
        $landlord = $this->issue($inspection, 'landlord', $this->landlord->id);
        $agent = $this->issue($inspection, 'agent');

        $tokens = [$tenant->token, $tenant2->token, $landlord->token, $agent->token];
        $this->assertCount(4, array_unique($tokens));
        foreach ($tokens as $t) {
            $this->assertGreaterThanOrEqual(48, strlen($t));
        }
        $this->assertSame($this->tenant->id, $tenant->party_contact_id);
        $this->assertSame($this->landlord->id, $landlord->party_contact_id);
        $this->assertSame($this->inspector->id, $agent->party_user_id, 'The agent party is the inspector who ran it.');
        $this->assertSame(route('rental-inspections.sign.show', $tenant->token), $tenant->url());
    }

    public function test_issuing_twice_returns_the_same_live_link(): void
    {
        $inspection = $this->inspection();
        $a = $this->issue($inspection, 'tenant', $this->tenant->id);
        $b = $this->issue($inspection, 'tenant', $this->tenant->id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, RentalInspectionSigningLink::where('party_role', 'tenant')->where('party_contact_id', $this->tenant->id)->count());
    }

    public function test_each_party_link_opens_the_full_report_read_only(): void
    {
        $inspection = $this->inspection();
        $links = [
            $this->issue($inspection, 'tenant', $this->tenant->id),
            $this->issue($inspection, 'tenant', $this->tenant2->id),
            $this->issue($inspection, 'landlord', $this->landlord->id),
            $this->issue($inspection, 'agent'),
        ];
        $this->guest();

        foreach ($links as $link) {
            $this->get($link->url())
                ->assertOk()
                ->assertSee('Lounge Ceiling')
                ->assertSee('Signatures')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                ->assertHeader('Referrer-Policy', 'no-referrer');
        }
    }

    public function test_a_link_shows_only_its_own_inspection(): void
    {
        $inspection = $this->inspection();
        $other = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => RentalInspection::TYPE_OUT, 'created_by_user_id' => $this->admin->id,
        ]);
        $item = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'SECRET OUT ITEM', 'created_by_user_id' => $this->admin->id,
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $other->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->admin->id, 'condition' => 'damaged', 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);

        $this->guest();
        $this->get($link->url())->assertOk()->assertSee('Lounge Ceiling')->assertDontSee('SECRET OUT ITEM');
    }

    // ═══ Wrong, revoked, expired ═══════════════════════════════════════════════

    public function test_a_wrong_token_is_the_uniform_unavailable_page_and_cannot_sign(): void
    {
        $this->inspection();
        $this->guest();

        $this->get(route('rental-inspections.sign.show', 'definitely-not-a-real-token'))->assertOk()->assertSee(self::UNAVAILABLE);
        $this->postJson(route('rental-inspections.sign.submit', 'definitely-not-a-real-token'), $this->signPayload())->assertStatus(404);
        $this->get(route('rental-inspections.sign.pdf', 'definitely-not-a-real-token'))->assertStatus(404);
        $this->assertSame(0, RentalInspectionSignature::count());
    }

    public function test_a_revoked_link_is_dead_and_a_fresh_one_is_a_different_token_that_works(): void
    {
        $inspection = $this->inspection();
        $old = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->links()->revoke($old, $this->admin);

        $this->guest();
        $this->get($old->url())->assertOk()->assertSee(self::UNAVAILABLE);
        $this->submit($old)->assertStatus(404);
        $this->assertSame(0, RentalInspectionSignature::count());

        $this->actingAs($this->admin);
        $new = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->assertNotSame($old->token, $new->token);
        $this->guest();
        $this->submit($new)->assertStatus(201);
        $this->assertSame('revoked', $old->fresh()->status());
        $this->assertContains('signing_link_revoked', RentalInspectionAuditLog::pluck('event')->all());
    }

    public function test_an_expired_link_is_dead_and_expiry_defaults_to_thirty_days_from_issue(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $link->expires_at->timestamp, 5);

        $link->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->guest();
        $this->get($link->url())->assertOk()->assertSee(self::UNAVAILABLE);
        $this->submit($link)->assertStatus(404);
        $this->assertSame('expired', $link->fresh()->status());
    }

    public function test_the_expiry_is_the_agencys_own_setting(): void
    {
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['signing_link_expiry_days' => 5]);
        $link = $this->issue($this->inspection(), 'tenant', $this->tenant->id);

        $this->assertEqualsWithDelta(now()->addDays(5)->timestamp, $link->expires_at->timestamp, 5);
        $this->assertSame(30, RentalInspectionSetting::signingLinkExpiryDaysFor(null), 'No agency: the neutral default.');
    }

    public function test_an_archived_or_cancelled_inspection_kills_its_links_and_restoring_revives_them(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);

        $inspection->delete();
        $this->guest();
        $this->get($link->url())->assertOk()->assertSee(self::UNAVAILABLE);
        $this->submit($link)->assertStatus(404);

        $this->actingAs($this->admin);
        RentalInspection::withTrashed()->find($inspection->id)->restore();
        $this->guest();
        $this->get($link->url())->assertOk()->assertDontSee(self::UNAVAILABLE);

        $this->actingAs($this->admin);
        $inspection->fresh()->cancel($this->admin, 'Wrong property.');
        $this->guest();
        $this->get($link->url())->assertOk()->assertSee(self::UNAVAILABLE);
        $this->submit($link)->assertStatus(404);
    }

    // ═══ Signing ═══════════════════════════════════════════════════════════════

    public function test_a_tenant_signs_from_their_link_and_everything_is_recorded(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $fingerprint = $inspection->reportFingerprint();
        $this->guest();

        $this->submit($link, ['comment' => 'The ceiling stain was already there.'], ['User-Agent' => 'Mozilla/5.0 (Test Phone)'])
            ->assertStatus(201)->assertJson(['ok' => true, 'outcome' => 'signed']);

        $sig = RentalInspectionSignature::where('rental_inspection_id', $inspection->id)->firstOrFail();
        $this->assertSame('tenant', $sig->party_role);
        $this->assertSame($this->tenant->id, $sig->party_contact_id);
        $this->assertSame('signed', $sig->disposition);
        $this->assertSame('link', $sig->signed_via);
        $this->assertSame($link->id, $sig->signing_link_id);
        $this->assertSame('Naledi Dlamini', $sig->signed_typed_name);
        $this->assertSame('203.0.113.9', $sig->signed_ip);
        $this->assertSame('Mozilla/5.0 (Test Phone)', $sig->signed_user_agent);
        $this->assertSame('The ceiling stain was already there.', $sig->signer_comment);
        $this->assertNotNull($sig->read_confirmed_at);
        $this->assertNotNull($sig->disposition_recorded_at);
        $this->assertSame($fingerprint, $sig->signed_report_fingerprint);
        $this->assertNull($sig->signed_on_device_by_user_id);
        $this->assertTrue(str_starts_with($sig->party_signature_path, RentalInspectionSignature::PRIVATE_PREFIX));
        Storage::disk('local')->assertExists(substr($sig->party_signature_path, strlen(RentalInspectionSignature::PRIVATE_PREFIX)));

        $link->refresh();
        $this->assertSame('signed', $link->status());
        $this->assertSame($sig->id, $link->signature_id);
        $this->assertNotNull($link->outcome_at);

        $log = RentalInspectionAuditLog::where('event', 'signed_by_link')->firstOrFail();
        $this->assertStringContainsString('Naledi Dlamini', (string) $log->summary);
    }

    public function test_the_landlord_signs_from_their_link_too(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'landlord', $this->landlord->id);
        $this->guest();

        $this->submit($link, ['typed_name' => 'Pieter van Wyk'])->assertStatus(201);

        $sig = RentalInspectionSignature::where('party_role', 'landlord')->firstOrFail();
        $this->assertSame($this->landlord->id, $sig->party_contact_id);
        $this->assertSame('link', $sig->signed_via);
    }

    public function test_signing_twice_is_refused_and_leaves_exactly_one_signature(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();

        $this->submit($link)->assertStatus(201);
        $this->submit($link)->assertStatus(409)->assertJson(['reason' => 'already_used']);
        $this->assertSame(1, RentalInspectionSignature::where('rental_inspection_id', $inspection->id)->count());
    }

    public function test_a_signed_link_still_opens_as_a_read_only_confirmation_with_a_download(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();
        $this->submit($link)->assertStatus(201);

        $this->get($link->url())->assertOk()
            ->assertSee('your signature is recorded')
            ->assertSee('data-qa="download-report"', false)
            ->assertDontSee('Sign the report');
    }

    public function test_the_party_can_download_the_report_pdf_from_their_link(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();

        $response = $this->get(route('rental-inspections.sign.pdf', $link->token));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_form_asks_for_name_signature_and_the_read_tick(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();

        $this->get($link->url())->assertOk()
            ->assertSee('Your full name')
            ->assertSee('I have read this inspection report.')
            ->assertSee('Comment or note (optional)')
            ->assertSee('I do not agree / cannot sign');

        $this->submit($link, ['typed_name' => 'N'])->assertStatus(422);
        $this->submit($link, ['signature_image' => null])->assertStatus(422)->assertJson(['message' => 'Please draw your signature.']);
        $this->submit($link, ['read_confirmed' => false])->assertStatus(422)->assertJson(['message' => 'Please tick that you have read this inspection report.']);
        $this->submit($link, ['signature_image' => 'data:image/png;base64,not-an-image'])->assertStatus(422);
        $this->assertSame(0, RentalInspectionSignature::count(), 'Nothing was recorded by any refused attempt.');
        $this->assertNull($link->fresh()->outcome, 'A refused attempt does not use the link up.');
    }

    // ═══ Declining / disputing — the agency's own reasons ═══════════════════════

    public function test_a_party_can_decline_with_one_of_the_agencys_own_reasons(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();

        $this->get($link->url())->assertSee('Disputes the recorded condition', false);

        $this->postJson(route('rental-inspections.sign.submit', $link->token), [
            'action' => 'decline', 'typed_name' => 'Naledi Dlamini',
            'reason_preset' => 'disputes_condition', 'reason_note' => 'The geyser was not working at move-in.',
        ])->assertStatus(201)->assertJson(['outcome' => 'declined']);

        $sig = RentalInspectionSignature::firstOrFail();
        $this->assertSame('refused', $sig->disposition);
        $this->assertSame('disputes_condition', $sig->refusal_reason_preset);
        $this->assertSame('The geyser was not working at move-in.', $sig->refusal_reason_note);
        $this->assertNull($sig->party_signature_path, 'A refusal never carries a signature image.');
        $this->assertSame('link', $sig->signed_via);
        $this->assertSame('declined', $link->fresh()->status());
        $this->get($link->url())->assertOk()->assertSee('Your response is recorded.');
    }

    public function test_declining_validates_the_reason_against_the_agencys_list_and_other_needs_a_note(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();
        $decline = fn (array $over) => $this->postJson(route('rental-inspections.sign.submit', $link->token), $over + ['action' => 'decline', 'typed_name' => 'Naledi Dlamini']);

        $decline([])->assertStatus(422)->assertJson(['message' => 'Please choose a reason.']);
        $decline(['reason_preset' => 'made_up'])->assertStatus(422);
        $decline(['reason_preset' => 'other'])->assertStatus(422)->assertJson(['message' => 'Please tell us the reason.']);
        $this->assertSame(0, RentalInspectionSignature::count());

        $decline(['reason_preset' => 'other', 'reason_note' => 'I was not given a copy.'])->assertStatus(201);
        $this->assertSame('other', RentalInspectionSignature::firstOrFail()->refusal_reason_preset);
    }

    public function test_the_reasons_are_the_agencys_own_edited_list(): void
    {
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'refusal_reason_presets' => [['key' => 'wrong_unit', 'label' => 'This is not my unit'], ['key' => 'other', 'label' => 'Other']],
        ]);
        $link = $this->issue($this->inspection(), 'tenant', $this->tenant->id);
        $this->guest();

        $this->get($link->url())->assertSee('This is not my unit')->assertDontSee('Disputes the recorded condition');
    }

    // ═══ When signing is open ══════════════════════════════════════════════════

    public function test_before_ready_to_sign_the_report_can_be_read_but_not_signed(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_IN, readyToSign: false);
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();

        $this->get($link->url())->assertOk()->assertSee('Lounge Ceiling')->assertSee('Signing opens when the agent finishes the inspection')->assertDontSee('Sign the report');
        $this->submit($link)->assertStatus(409)->assertJson(['reason' => 'not_ready']);
        $this->assertSame(0, RentalInspectionSignature::count());
        $this->assertNull($link->fresh()->outcome);
    }

    public function test_a_completed_inspections_link_stays_a_read_only_view_of_the_signed_report(): void
    {
        $inspection = $this->inspection();
        $tenantLink = $this->issue($inspection, 'tenant', $this->tenant->id);
        $unsignedLink = $this->issue($inspection, 'tenant', $this->tenant2->id);
        $this->guest();
        $this->submit($tenantLink)->assertStatus(201);

        $this->actingAs($this->admin);
        $this->completeInspection($inspection, tenantsAlreadySigned: [$this->tenant->id]);

        $this->guest();
        $this->get($tenantLink->url())->assertOk()->assertSee('Lounge Ceiling')->assertSee('Naledi Dlamini')->assertSee('The inspection is now complete');
        $this->get($unsignedLink->url())->assertOk()->assertSee('This inspection is complete')->assertDontSee('Sign the report');
        $this->submit($unsignedLink)->assertStatus(409);
    }

    public function test_when_the_setting_is_off_there_are_no_links_and_nothing_can_be_signed(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['signing_link_enabled' => false]);

        $panel = $this->links()->panel($inspection->fresh());
        $this->assertFalse($panel['enabled']);
        $this->postJson(route('corex.rental-inspections.signing-links.issue', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant2->id])
            ->assertStatus(409)->assertJson(['reason' => 'not_enabled']);
        $this->get(route('corex.rental-inspections.show', $inspection))->assertOk()->assertDontSee('data-qa="signing-links-card"', false);

        $this->guest();
        $this->submit($link)->assertStatus(409)->assertJson(['reason' => 'not_enabled']);
        $this->assertSame(0, RentalInspectionSignature::count());
    }

    public function test_the_setting_defaults_on_and_a_malformed_expiry_falls_back_to_the_default(): void
    {
        $this->assertTrue(RentalInspectionSetting::signingLinkEnabledFor($this->agency->id));
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['signing_link_expiry_days' => 0]);
        $this->assertSame(30, RentalInspectionSetting::signingLinkExpiryDaysFor($this->agency->id));
    }

    public function test_an_ad_hoc_inspection_has_no_signing_links(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_AD_HOC);

        $this->assertFalse($this->links()->panel($inspection)['enabled']);
        $this->postJson(route('corex.rental-inspections.signing-links.issue', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id])
            ->assertStatus(409)->assertJson(['reason' => 'not_signable_type']);
    }

    public function test_a_party_who_already_has_an_outcome_recorded_gets_no_new_link(): void
    {
        $inspection = $this->inspection();
        RentalInspectionSignature::capture($inspection, 'tenant', 'signed', ['party_contact_id' => $this->tenant->id, 'party_signature_path' => '/fake/t.png', 'recorded_by_user_id' => $this->admin->id]);

        $this->postJson(route('corex.rental-inspections.signing-links.issue', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id])
            ->assertStatus(409)->assertJson(['reason' => 'already_recorded']);
        $row = $this->row($this->links()->panel($inspection), 'tenant:' . $this->tenant->id);
        $this->assertFalse($row['can_issue']);
        $this->assertSame('Signed', $row['recorded']['label']);
    }

    public function test_a_link_cannot_sign_for_a_party_the_agent_already_recorded_by_another_route(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        // The agent records a paper refusal for the same tenant AFTER the link went out.
        RentalInspectionSignature::capture($inspection, 'tenant', 'refused', ['party_contact_id' => $this->tenant->id, 'refusal_reason_preset' => 'not_present', 'recorded_by_user_id' => $this->admin->id]);
        $this->guest();

        $this->submit($link)->assertStatus(409)->assertJson(['reason' => 'already_recorded']);
        $this->assertSame(1, RentalInspectionSignature::count());
    }

    public function test_the_agents_link_opens_the_report_but_the_agent_signs_in_corex_with_their_pin(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'agent');
        $this->guest();

        $this->get($link->url())->assertOk()->assertSee('Lounge Ceiling')->assertSee('signs in CoreX, with their own login and PIN')->assertDontSee('Sign the report');
        $this->submit($link)->assertStatus(409)->assertJson(['reason' => 'agent_link']);
        $this->assertSame(0, RentalInspectionSignature::count());
    }

    // ═══ Status per party ══════════════════════════════════════════════════════

    public function test_status_moves_through_not_sent_sent_opened_signed(): void
    {
        $inspection = $this->inspection();
        $key = 'tenant:' . $this->tenant->id;
        $this->assertNull($this->row($this->links()->panel($inspection), $key)['link']);

        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->assertSame('not_sent', $this->row($this->links()->panel($inspection), $key)['link']['status']);

        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))->assertOk();
        $this->assertSame('sent', $this->row($this->links()->panel($inspection), $key)['link']['status']);

        $this->guest();
        $this->get($link->url())->assertOk();
        $this->actingAs($this->admin);
        $row = $this->row($this->links()->panel($inspection), $key);
        $this->assertSame('opened', $row['link']['status']);
        $this->assertNotNull($row['link']['opened_at']);

        $this->guest();
        $this->submit($link)->assertStatus(201);
        $this->actingAs($this->admin);
        $row = $this->row($this->links()->panel($inspection), $key);
        $this->assertSame('signed', $row['link']['status']);
        $this->assertSame('Signed', $row['recorded']['label']);
        $this->assertSame('from their link', $row['recorded']['via']);
    }

    public function test_opening_counts_and_an_agent_previewing_while_logged_in_does_not_count_as_the_party_opening_it(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);

        $this->get($link->url())->assertOk(); // the agent, logged in
        $this->assertNull($link->fresh()->first_opened_at);

        $this->guest();
        $this->get($link->url())->assertOk();
        $this->get($link->url())->assertOk();
        $this->assertSame(2, $link->fresh()->open_count);
        $this->assertNotNull($link->fresh()->first_opened_at);
    }

    public function test_a_declined_link_reads_declined_on_the_agents_panel(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();
        $this->postJson(route('rental-inspections.sign.submit', $link->token), ['action' => 'decline', 'typed_name' => 'Naledi Dlamini', 'reason_preset' => 'not_present'])->assertStatus(201);
        $this->actingAs($this->admin);

        $row = $this->row($this->links()->panel($inspection), 'tenant:' . $this->tenant->id);
        $this->assertSame('declined', $row['link']['status']);
        $this->assertSame('Declined / disputed', $row['link']['status_label']);
    }

    // ═══ Sending: email, WhatsApp, copy, send-to-all, resend, revoke ═══════════

    public function test_emailing_a_link_sends_one_mail_with_that_partys_link_and_records_it(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);

        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))
            ->assertOk()->assertJsonPath('result.status', 'sent');

        Mail::assertSent(RentalInspectionSigningLinkMail::class, function (RentalInspectionSigningLinkMail $mail) use ($link) {
            return $mail->signingUrl === $link->url() && $mail->canSign === true && $mail->recipientName === 'Naledi Dlamini'
                && $mail->hasTo(self::TEST_ADDRESS);
        });
        Mail::assertSent(RentalInspectionSigningLinkMail::class, 1);
        $link->refresh();
        $this->assertSame('email', $link->last_sent_channel);
        $this->assertSame('naledi@example.co.za', $link->last_sent_to);
        $this->assertSame(1, $link->send_count);
        $this->assertContains('signing_link_sent', RentalInspectionAuditLog::pluck('event')->all());

        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))->assertOk();
        $this->assertSame(2, $link->fresh()->send_count, 'Resend uses the same link.');
        $this->assertSame($link->token, $link->fresh()->token);
    }

    public function test_a_party_with_no_email_is_skipped_with_a_reason_not_silently_dropped(): void
    {
        $noEmail = $this->contact('Thandi', 'Mokoena', null);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $noEmail->id]);
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $noEmail->id);

        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))
            ->assertOk()->assertJsonPath('result.status', 'skipped');

        Mail::assertNothingSent();
        $row = $this->row($this->links()->panel($inspection), 'tenant:' . $noEmail->id);
        $this->assertSame('not_sent', $row['link']['status']);
        $this->assertSame('skipped', $row['link']['last_send_status']);
        $this->assertStringContainsString('No email address', (string) $row['link']['last_send_error']);
    }

    public function test_send_to_all_emails_every_tenant_and_the_landlord_but_not_the_agent(): void
    {
        $inspection = $this->inspection();

        $response = $this->postJson(route('corex.rental-inspections.signing-links.send-all', $inspection))->assertOk();

        $this->assertCount(3, $response->json('results'));
        $this->assertSame(['sent', 'sent', 'sent'], array_column($response->json('results'), 'status'));
        Mail::assertSent(RentalInspectionSigningLinkMail::class, 3);
        $this->assertSame(0, RentalInspectionSigningLink::where('party_role', 'agent')->count());
    }

    public function test_send_to_all_skips_someone_already_recorded_and_says_so(): void
    {
        $inspection = $this->inspection();
        RentalInspectionSignature::capture($inspection, 'tenant', 'signed', ['party_contact_id' => $this->tenant->id, 'party_signature_path' => '/fake/t.png', 'recorded_by_user_id' => $this->admin->id]);

        $results = collect($this->postJson(route('corex.rental-inspections.signing-links.send-all', $inspection))->assertOk()->json('results'));

        $this->assertSame('already_recorded', $results->firstWhere('name', 'Naledi Dlamini')['status']);
        Mail::assertSent(RentalInspectionSigningLinkMail::class, 2);
    }

    public function test_whatsapp_and_copy_hand_back_the_link_and_count_as_sent(): void
    {
        $inspection = $this->inspection();
        $this->tenant->update(['phone' => '082 555 0101']);

        $wa = $this->postJson(route('corex.rental-inspections.signing-links.issue', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id, 'channel' => 'whatsapp'])->assertStatus(201);
        $link = RentalInspectionSigningLink::firstOrFail();
        $this->assertStringStartsWith('https://wa.me/27825550101?text=', $wa->json('whatsapp_url'));
        $this->assertStringContainsString(rawurlencode($link->url()), $wa->json('whatsapp_url'));
        $this->assertSame('whatsapp', $link->fresh()->last_sent_channel);
        $this->assertSame('sent', $link->fresh()->status());

        $copy = $this->postJson(route('corex.rental-inspections.signing-links.issue', $inspection), ['party_role' => 'landlord', 'party_contact_id' => $this->landlord->id, 'channel' => 'copied'])->assertStatus(201);
        $this->assertSame(RentalInspectionSigningLink::where('party_role', 'landlord')->first()->url(), $copy->json('url'));
        $this->assertStringStartsWith('https://wa.me/?text=', $copy->json('whatsapp_url'), 'No phone on file: WhatsApp still opens so the agent can pick the chat.');
        Mail::assertNothingSent();
    }

    public function test_revoke_kills_the_link_at_once_and_resend_after_revoke_needs_a_new_link(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);

        $this->postJson(route('corex.rental-inspections.signing-links.revoke', [$inspection, $link]))->assertOk();
        $this->assertSame('revoked', $link->fresh()->status());
        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))->assertStatus(409)->assertJson(['reason' => 'unavailable']);
        Mail::assertNothingSent();

        $again = $this->postJson(route('corex.rental-inspections.signing-links.issue', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id])->assertStatus(201);
        $this->assertNotSame($link->url(), $again->json('url'));
    }

    // ═══ QR code ═══════════════════════════════════════════════════════════════

    public function test_the_qr_code_points_at_exactly_the_partys_own_link(): void
    {
        $inspection = $this->inspection();
        $tenantLink = $this->issue($inspection, 'tenant', $this->tenant->id);
        $landlordLink = $this->issue($inspection, 'landlord', $this->landlord->id);

        $response = $this->getJson(route('corex.rental-inspections.signing-links.qr', [$inspection, $tenantLink]))->assertOk();

        $this->assertSame($tenantLink->url(), $response->json('url'));
        $expected = (new PngWriter())->write(new QrCode(data: $tenantLink->url(), errorCorrectionLevel: ErrorCorrectionLevel::High, size: 520, margin: 12))->getDataUri();
        $this->assertSame($expected, $response->json('data_uri'), 'The image encodes the link and nothing else.');
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('data_uri'));
        $other = (new PngWriter())->write(new QrCode(data: $landlordLink->url(), errorCorrectionLevel: ErrorCorrectionLevel::High, size: 520, margin: 12))->getDataUri();
        $this->assertNotSame($other, $response->json('data_uri'));
        $this->assertSame('qr', $tenantLink->fresh()->last_sent_channel);
        $this->assertSame('sent', $tenantLink->fresh()->status());
    }

    public function test_a_revoked_links_qr_is_refused(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->links()->revoke($link, $this->admin);

        $this->getJson(route('corex.rental-inspections.signing-links.qr', [$inspection, $link]))->assertStatus(409);
    }

    public function test_the_panel_and_qr_control_are_on_the_inspection_page_and_the_recording_screen(): void
    {
        $inspection = $this->inspection();

        $this->get(route('corex.rental-inspections.show', $inspection))->assertOk()
            ->assertSee('data-qa="signing-links-panel"', false)->assertSee('Show QR')->assertSee('Sign on this device')->assertSee('Email everyone their link');
        $this->get(route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'inspections']))->assertOk()
            ->assertSee('data-qa="signing-links-panel"', false)->assertSee('Show QR');
    }

    // ═══ Sign on this device (the agent's own logged-in phone) ═════════════════

    public function test_signing_on_the_agents_device_records_that_it_was_the_agents_device_and_which_agent(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);

        $this->get(route('corex.rental-inspections.signing-links.device', [$inspection, $link]))->assertOk()
            ->assertSee('data-qa="device-banner"', false)->assertSee('Aileen Agent')->assertSee('Naledi Dlamini')->assertSee('Lounge Ceiling');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link]), $this->signPayload(), ['User-Agent' => 'AgentPhone/1.0'])
            ->assertStatus(201);

        $sig = RentalInspectionSignature::firstOrFail();
        $this->assertSame('agent_device', $sig->signed_via);
        $this->assertSame($this->admin->id, $sig->signed_on_device_by_user_id);
        $this->assertSame($this->admin->id, $sig->recorded_by_user_id);
        $this->assertSame($link->id, $sig->signing_link_id);
        $this->assertSame('Naledi Dlamini', $sig->signed_typed_name);
        $this->assertSame('198.51.100.7', $sig->signed_ip);
        $this->assertSame('AgentPhone/1.0', $sig->signed_user_agent);
        $this->assertSame('signed', $link->fresh()->status());
        $log = RentalInspectionAuditLog::where('event', 'signed_by_link')->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertStringContainsString("on the agent's device", (string) $log->summary);
    }

    public function test_the_device_signing_needs_a_login_and_the_create_permission(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $url = route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link]);

        $this->guest();
        $this->postJson($url, $this->signPayload())->assertStatus(401);

        // An agent who can only VIEW inspections cannot sign on their device.
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $this->agency->id], ['label' => 'Agent']);
        foreach (['rental_inspections.view' => 'all', 'access_properties' => null] as $key => $scope) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
        $this->actingAs($this->inspector);
        $this->postJson($url, $this->signPayload())->assertStatus(403);
        $this->get(route('corex.rental-inspections.signing-links.device', [$inspection, $link]))->assertStatus(403);
        $this->assertSame(0, RentalInspectionSignature::count());
    }

    public function test_someone_from_another_agency_never_reaches_the_device_signing(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);

        Auth::logout();
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $stranger = User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'admin']);
        $this->actingAs($stranger);

        $this->assertContains($this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link]), $this->signPayload())->status(), [403, 404]);
        $this->assertContains($this->get(route('corex.rental-inspections.signing-links.device', [$inspection, $link]))->status(), [403, 404]);
        $this->assertSame(0, RentalInspectionSignature::count());
    }

    public function test_the_agents_device_cannot_sign_through_a_revoked_or_expired_link(): void
    {
        $inspection = $this->inspection();
        $revoked = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->links()->revoke($revoked, $this->admin);
        $expired = $this->issue($inspection, 'tenant', $this->tenant2->id);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        foreach ([$revoked, $expired] as $link) {
            $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link]), $this->signPayload())->assertStatus(409)->assertJson(['reason' => 'unavailable']);
            $this->get(route('corex.rental-inspections.signing-links.device', [$inspection, $link]))->assertOk()->assertSee(self::UNAVAILABLE);
        }
        $this->assertSame(0, RentalInspectionSignature::count());
    }

    public function test_the_device_route_refuses_a_link_that_belongs_to_another_inspection(): void
    {
        $inspection = $this->inspection();
        $other = $this->inspection(RentalInspection::TYPE_OUT);
        $link = $this->issue($other, 'tenant', $this->tenant->id);

        $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link]), $this->signPayload())->assertStatus(404);
        $this->assertSame(0, RentalInspectionSignature::count());
    }

    // ═══ Scoping and permissions on the agent's panel ══════════════════════════

    public function test_another_agencys_user_cannot_list_issue_email_revoke_or_open_the_qr(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        Auth::logout();
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $stranger = User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'admin']);
        $this->actingAs($stranger);

        $statuses = [
            $this->getJson(route('corex.rental-inspections.signing-links.index', $inspection))->status(),
            $this->postJson(route('corex.rental-inspections.signing-links.issue', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id])->status(),
            $this->postJson(route('corex.rental-inspections.signing-links.send-all', $inspection))->status(),
            $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))->status(),
            $this->postJson(route('corex.rental-inspections.signing-links.revoke', [$inspection, $link]))->status(),
            $this->getJson(route('corex.rental-inspections.signing-links.qr', [$inspection, $link]))->status(),
        ];
        foreach ($statuses as $status) {
            $this->assertContains($status, [403, 404]);
        }
        Mail::assertNothingSent();
        $this->assertNull($link->fresh()->revoked_at);
    }

    public function test_managing_links_needs_the_public_link_permission(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $this->agency->id], ['label' => 'Agent']);
        foreach (['rental_inspections.view' => 'all', 'rental_inspections.create' => null, 'access_properties' => null] as $key => $scope) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
        $this->actingAs($this->inspector);

        $this->getJson(route('corex.rental-inspections.signing-links.index', $inspection))->assertOk();
        $this->postJson(route('corex.rental-inspections.signing-links.issue', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant2->id])->assertStatus(403);
        $this->postJson(route('corex.rental-inspections.signing-links.send-all', $inspection))->assertStatus(403);
        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))->assertStatus(403);
        $this->postJson(route('corex.rental-inspections.signing-links.revoke', [$inspection, $link]))->assertStatus(403);
        $this->getJson(route('corex.rental-inspections.signing-links.qr', [$inspection, $link]))->assertStatus(403);
        Mail::assertNothingSent();
    }

    public function test_the_panel_lists_every_party_with_their_own_status(): void
    {
        $inspection = $this->inspection();
        $json = $this->getJson(route('corex.rental-inspections.signing-links.index', $inspection))->assertOk()->json();

        $this->assertTrue($json['enabled']);
        $this->assertTrue($json['ready_to_sign']);
        $this->assertSame(30, $json['expiry_days']);
        $this->assertSame(
            ['tenant:' . $this->tenant->id, 'tenant:' . $this->tenant2->id, 'landlord:' . $this->landlord->id, 'agent:' . $this->inspector->id],
            array_column($json['rows'], 'key'),
        );
        $this->assertSame([true, true, true, true], array_column($json['rows'], 'can_issue'));
    }

    // ═══ Completion — exactly as today ═════════════════════════════════════════

    /** @param array<int, int> $tenantsAlreadySigned contact ids of tenants who have already signed (any route) */
    private function completeInspection(RentalInspection $inspection, array $tenantsAlreadySigned = []): void
    {
        foreach ([$this->tenant, $this->tenant2] as $tenant) {
            if (! in_array($tenant->id, $tenantsAlreadySigned, true)) {
                RentalInspectionSignature::capture($inspection, 'tenant', 'signed', ['party_contact_id' => $tenant->id, 'party_signature_path' => '/fake/t.png', 'recorded_by_user_id' => $this->admin->id]);
            }
        }
        if (! $inspection->signatures()->where('party_role', 'landlord')->exists()) {
            RentalInspectionSignature::capture($inspection, 'landlord', 'signed', ['party_contact_id' => $this->landlord->id, 'party_signature_path' => '/fake/l.png', 'recorded_by_user_id' => $this->admin->id]);
        }
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG,
        ])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
    }

    public function test_link_signatures_alone_do_not_complete_the_inspection_the_agent_still_signs_and_completes(): void
    {
        $inspection = $this->inspection();
        $this->guest();
        foreach ([['tenant', $this->tenant, 'Naledi Dlamini'], ['tenant', $this->tenant2, 'Sipho Khumalo'], ['landlord', $this->landlord, 'Pieter van Wyk']] as [$role, $contact, $name]) {
            $this->actingAs($this->admin);
            $link = $this->issue($inspection, $role, $contact->id);
            $this->guest();
            $this->submit($link, ['typed_name' => $name])->assertStatus(201);
        }

        $this->assertSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $inspection->fresh()->status, 'Link signing never completes the inspection by itself.');
        $this->assertSame(3, RentalInspectionSignature::where('signed_via', 'link')->count());
        $this->assertSame([], $inspection->fresh()->outstandingSignatories()->all(), 'All three non-agent parties are accounted for.');

        $this->actingAs($this->admin);
        // Complete is refused until the agent has signed — the agent-signs-last rule is unchanged.
        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertStatus(409);
        Mail::assertNotSent(SignedDocumentDistributionMail::class);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG,
        ])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
        // The completion copies go out exactly as they do for any inspection: tenants, landlord, inspector, creator.
        Mail::assertSent(SignedDocumentDistributionMail::class);
        $this->assertGreaterThanOrEqual(4, \App\Models\SignedDocumentDistributionLog::where('distributable_id', $inspection->id)->where('channel', 'email')->where('status', 'sent')->count());
    }

    public function test_an_interim_inspection_is_signed_by_link_like_in_and_out(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_INTERIM);
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->guest();

        $this->submit($link)->assertStatus(201);
        $this->assertSame('link', RentalInspectionSignature::firstOrFail()->signed_via);
    }

    // ═══ Evidence fingerprint (the held "edited after signing" flag records against this) ═══

    public function test_the_report_fingerprint_changes_when_the_report_does_and_not_otherwise(): void
    {
        $inspection = $this->inspection();
        $before = $inspection->reportFingerprint();
        $this->assertSame($before, $inspection->fresh()->reportFingerprint(), 'Asking twice gives the same value.');

        $item = RentalInspectionItem::first();
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->admin->id, 'condition' => 'damaged', 'notes' => 'Water stain.',
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);
        $this->assertNotSame($before, $inspection->fresh()->reportFingerprint());

        $mid = $inspection->fresh()->reportFingerprint();
        $inspection->forceFill(['overall_notes' => 'Tenant present throughout.'])->save();
        $this->assertNotSame($mid, $inspection->fresh()->reportFingerprint());
    }

    // ═══ Settings reach the screen and the wizard ═══════════════════════════════

    public function test_the_two_settings_save_from_the_settings_page_and_a_wizard_style_post_cannot_wipe_them(): void
    {
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['signing_link_enabled' => false, 'signing_link_expiry_days' => 12]);
        $base = ['fault_report_window_days' => 7, 'out_inspection_signing_window_days' => 7];

        // A step that renders neither control posts without them: nothing changes.
        $this->post(route('corex.settings.rental-inspections.update'), $base)->assertRedirect();
        $row = RentalInspectionSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->first();
        $this->assertFalse((bool) $row->signing_link_enabled);
        $this->assertSame(12, (int) $row->signing_link_expiry_days);

        $this->post(route('corex.settings.rental-inspections.update'), $base + ['signing_link_enabled' => '1', 'signing_link_expiry_days' => '45'])->assertRedirect();
        $row->refresh();
        $this->assertTrue((bool) $row->signing_link_enabled);
        $this->assertSame(45, (int) $row->signing_link_expiry_days);

        $this->post(route('corex.settings.rental-inspections.update'), $base + ['signing_link_expiry_days' => '0'])->assertSessionHasErrors('signing_link_expiry_days');
        $this->get(route('corex.settings.rental-inspections.edit'))->assertOk()
            ->assertSee('Let tenants and landlords sign an inspection from a personal link')->assertSee('Days a personal signing link stays live');
    }

    public function test_both_settings_are_declared_in_the_setup_wizard_with_an_explanation_and_a_consequence(): void
    {
        $controls = collect(config('agency-onboarding-copy'))
            ->flatMap(fn ($step) => is_array($step) ? ($step['controls'] ?? []) : [])
            ->keyBy('key');

        foreach (['signing_link_enabled' => 'toggle', 'signing_link_expiry_days' => 'number'] as $key => $type) {
            $this->assertTrue($controls->has($key), "{$key} must be offered in the Setup Wizard.");
            $this->assertSame($type, $controls[$key]['type']);
            $this->assertSame('rental_inspections', $controls[$key]['source']);
            $this->assertNotSame('', trim((string) $controls[$key]['explain']));
            $this->assertNotSame('', trim((string) $controls[$key]['affects']));
        }
        $this->assertSame(30, $controls['signing_link_expiry_days']['default']);
        $this->assertSame(1, $controls['signing_link_enabled']['default']);
    }

    // ═══ Multi-agency ══════════════════════════════════════════════════════════

    public function test_another_agencys_link_and_settings_are_independent(): void
    {
        $inspection = $this->inspection();
        $mine = $this->issue($inspection, 'tenant', $this->tenant->id);

        Auth::logout();
        $other = Agency::create(['name' => 'Durban Lettings', 'slug' => 'durban-' . uniqid()]);
        RentalInspectionSetting::updateOrCreate(['agency_id' => $other->id], ['signing_link_enabled' => false, 'signing_link_expiry_days' => 3]);
        $this->actingAs($this->admin);

        $this->assertTrue(RentalInspectionSetting::signingLinkEnabledFor($this->agency->id));
        $this->assertFalse(RentalInspectionSetting::signingLinkEnabledFor($other->id));
        $this->assertSame(3, RentalInspectionSetting::signingLinkExpiryDaysFor($other->id));
        $this->assertSame(30, RentalInspectionSetting::signingLinkExpiryDaysFor($this->agency->id));
        $this->guest();
        $this->submit($mine)->assertStatus(201);
    }

    public function test_the_email_carries_no_hardcoded_agency_wording(): void
    {
        $inspection = $this->inspection();
        $link = $this->issue($inspection, 'tenant', $this->tenant->id);
        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))->assertOk();

        Mail::assertSent(RentalInspectionSigningLinkMail::class, function (RentalInspectionSigningLinkMail $mail) {
            $html = $mail->render();
            $this->assertStringNotContainsStringIgnoringCase('Home Finders', $html);
            $this->assertStringNotContainsStringIgnoringCase('HFC', $html);
            $this->assertStringContainsString('READ AND SIGN THE REPORT', $html);

            return true;
        });
    }
}
