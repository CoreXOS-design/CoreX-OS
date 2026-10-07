<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Mail\Signatures\SignedDocumentMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalPortalSetting;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use App\Services\Rentals\RentalPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * .ai/specs/rental-portal-access.md §16 — signing a lease gives its tenant(s) and landlord(s) portal access
 * (agency setting, default ON, in the Setup Wizard) and the signed-lease copy each of them is emailed carries
 * their personal portal link — only what the portal really offers that role, and never when the agency has
 * portal access off.
 */
final class LeasePortalSigningTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private Contact $landlord;
    private Lease $lease;
    private SignatureTemplate $envelope;
    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->agent);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->tenant = $this->contact('Thandi', 'thandi@example.test');
        $this->landlord = $this->contact('Lerato', 'lerato@example.test');
        DB::table('contact_property')->insert([
            'contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->document = Document::create([
            'name' => 'Residential Lease Agreement', 'document_type' => 'agreement', 'owner_id' => $this->agent->id,
            'agency_id' => $this->agency->id, 'web_template_data' => ['merged_html' => '<p>x</p>'],
        ]);
        $this->envelope = SignatureTemplate::create([
            'agency_id' => $this->agency->id, 'document_id' => $this->document->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_TENANT, 'created_by' => $this->agent->id,
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'signing_status' => Lease::SIGNING_OUT_FOR_SIGNING, 'signature_template_id' => $this->envelope->id,
            'agreement_document_id' => $this->document->id,
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
    }

    private function contact(string $first, ?string $email): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => 'Test', 'email' => $email, 'id_number' => '8002025009081',
        ]);
    }

    private function service(): RentalPortalAccessService
    {
        return app(RentalPortalAccessService::class);
    }

    private function setting(array $values): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], $values);
    }

    private function finalise(): void
    {
        $this->envelope->update([
            'status' => SignatureTemplate::STATUS_COMPLETED, 'completed_at' => now(),
            'finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED,
        ]);
        event(new SignatureEnvelopeFinalized($this->envelope->id, $this->document->id, $this->agency->id));
    }

    private function sendCopies(): void
    {
        foreach ([['tenant', $this->tenant], ['landlord', $this->landlord]] as $i => [$role, $c]) {
            SignatureRequest::create([
                'signature_template_id' => $this->envelope->id, 'party_role' => $role, 'role_index' => 1, 'signing_order' => $i + 1,
                'signer_name' => $c->full_name, 'signer_email' => $c->email, 'token' => Str::random(48),
                'token_expires_at' => now()->addDays(14), 'status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now(),
            ]);
        }
        $this->envelope->update(['status' => SignatureTemplate::STATUS_COMPLETED]);
        $send = new ReflectionMethod(SignatureService::class, 'sendCompletionEmails');
        $send->setAccessible(true);
        $send->invoke(app(SignatureService::class), $this->envelope->fresh(), null, []);
    }

    // ── access is created when the lease is signed ───────────────────────────────────────────

    public function test_signing_the_lease_gives_the_tenant_and_the_landlord_portal_access(): void
    {
        $this->finalise();

        $this->assertSame(Lease::SIGNING_SIGNED, $this->lease->fresh()->signing_status);
        $this->assertNotNull($this->tenant->fresh()->client_user_id);
        $this->assertNotNull($this->landlord->fresh()->client_user_id);
        $this->assertSame(2, ClientUser::count());
    }

    public function test_with_automatic_access_switched_off_signing_creates_nothing(): void
    {
        $this->setting(['auto_portal_access_on_signing' => false]);

        $this->finalise();

        $this->assertSame(Lease::SIGNING_SIGNED, $this->lease->fresh()->signing_status);
        $this->assertSame(0, ClientUser::count());
    }

    public function test_the_default_is_on_for_an_agency_that_never_touched_the_setting(): void
    {
        $this->assertTrue(RentalPortalSetting::autoPortalAccessOnSigningFor($this->agency->id));
        $this->assertSame(0, RentalPortalSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'read-time default: nothing is written');
    }

    public function test_an_audience_whose_portal_is_off_is_skipped_and_the_other_is_not(): void
    {
        $this->setting(['landlord_portal_enabled' => false]);

        $this->finalise();

        $this->assertNotNull($this->tenant->fresh()->client_user_id);
        $this->assertNull($this->landlord->fresh()->client_user_id);
    }

    public function test_a_party_without_an_email_is_skipped_and_signing_still_completes(): void
    {
        $this->landlord->forceFill(['email' => null])->saveQuietly();

        $this->finalise();

        $this->assertSame(Lease::SIGNING_SIGNED, $this->lease->fresh()->signing_status);
        $this->assertNotNull($this->tenant->fresh()->client_user_id);
        $this->assertNull($this->landlord->fresh()->client_user_id);
    }

    public function test_a_party_whose_login_already_exists_for_their_own_email_is_attached(): void
    {
        ClientUser::create(['email' => 'thandi@example.test', 'created_by_agency_id' => $this->agency->id]);
        // Thandi's own email IS on her contact, so this is the same person — attached, not skipped.
        $out = $this->service()->provisionForSignedLease($this->lease->fresh());

        $this->assertSame('attached', $out[$this->tenant->id]);
    }

    // ── the signed-lease copy carries the link ───────────────────────────────────────────────

    public function test_each_signers_copy_carries_their_own_link_and_only_what_their_role_offers(): void
    {
        $this->sendCopies();

        Mail::assertSent(SignedDocumentMail::class, 2);
        Mail::assertSent(SignedDocumentMail::class, function (SignedDocumentMail $m) {
            if (! $m->hasTo('thandi@example.test')) {
                return false;
            }
            $html = $m->render();

            return str_contains($html, 'Your CoreX portal')
                && str_contains($html, url('/portal') . '?email=thandi%40example.test')
                && str_contains($html, 'report a fault')
                && ! str_contains($html, 'approve or decline repair decisions')
                && ! str_contains($html, 'lerato%40example.test');
        });
        Mail::assertSent(SignedDocumentMail::class, function (SignedDocumentMail $m) {
            if (! $m->hasTo('lerato@example.test')) {
                return false;
            }
            $html = $m->render();

            return str_contains($html, url('/portal') . '?email=lerato%40example.test')
                && stripos($html, 'approve or decline repair decisions') !== false
                && ! str_contains($html, 'report a fault');
        });
    }

    public function test_a_person_who_is_both_tenant_and_landlord_gets_one_link_listing_both(): void
    {
        DB::table('contact_property')->insert([
            'contact_id' => $this->tenant->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $block = $this->service()->mailBlockForLease($this->lease->fresh(), 'thandi@example.test');

        $this->assertSame(['tenant', 'landlord'], $block['roles']);
        $this->assertCount(2, $block['offers']);
    }

    public function test_no_link_when_the_agency_has_that_portal_switched_off(): void
    {
        $this->setting(['tenant_portal_enabled' => false]);
        $this->sendCopies();

        Mail::assertSent(SignedDocumentMail::class, fn (SignedDocumentMail $m) => $m->hasTo('thandi@example.test') && ! str_contains($m->render(), 'Your CoreX portal'));
        Mail::assertSent(SignedDocumentMail::class, fn (SignedDocumentMail $m) => $m->hasTo('lerato@example.test') && str_contains($m->render(), 'Your CoreX portal'));
    }

    public function test_no_link_when_automatic_access_is_off(): void
    {
        $this->setting(['auto_portal_access_on_signing' => false]);
        $this->sendCopies();

        Mail::assertSent(SignedDocumentMail::class, 2);
        Mail::assertNotSent(SignedDocumentMail::class, fn (SignedDocumentMail $m) => str_contains($m->render(), 'Your CoreX portal'));
    }

    public function test_no_link_for_a_signer_who_is_not_a_party_to_the_lease_or_for_a_placeholder_address(): void
    {
        $this->assertNull($this->service()->mailBlockForLease($this->lease->fresh(), 'witness@example.test'));
        $this->assertNull($this->service()->mailBlockForLease($this->lease->fresh(), 'thandi@corexclient.co.za'));
    }

    public function test_a_document_that_is_not_a_lease_never_gets_a_portal_block(): void
    {
        $other = SignatureTemplate::create([
            'agency_id' => $this->agency->id, 'document_id' => $this->document->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_COMPLETED, 'created_by' => $this->agent->id,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $other->id, 'party_role' => 'seller', 'role_index' => 1, 'signing_order' => 1,
            'signer_name' => 'Thandi Test', 'signer_email' => 'thandi@example.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14), 'status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now(),
        ]);
        $send = new ReflectionMethod(SignatureService::class, 'sendCompletionEmails');
        $send->setAccessible(true);
        $send->invoke(app(SignatureService::class), $other->fresh(), null, []);

        Mail::assertSent(SignedDocumentMail::class, fn (SignedDocumentMail $m) => ! str_contains($m->render(), 'Your CoreX portal'));
    }

    public function test_the_setting_has_a_wizard_control_with_a_saver_and_a_settings_page_toggle(): void
    {
        $step = config('agency-onboarding-copy.leases');
        $control = collect($step['controls'])->firstWhere('key', 'auto_portal_access_on_signing');
        $this->assertNotNull($control, 'The wizard must surface the setting (non-negotiable #10a).');
        $this->assertSame('rental_portal', $control['source']);
        $this->assertSame(1, $control['default']);
        $this->assertGreaterThan(40, strlen($control['explain']));
        $this->assertGreaterThan(40, strlen($control['affects']));

        $savers = collect($step['savers'])->where('controller', \App\Http\Controllers\CoreX\RentalPortalSettingsController::class)->pluck('method')->all();
        $this->assertContains('updateAutoPortalAccessOnSigning', $savers);

        $this->actingAs($this->agent)->get(route('corex.settings.rental-portal.edit'))
            ->assertOk()->assertSee('auto_portal_access_on_signing', false);
    }

    public function test_the_saver_leaves_the_value_alone_when_the_field_is_not_posted(): void
    {
        $this->setting(['auto_portal_access_on_signing' => false]);

        $this->actingAs($this->agent)->post(route('corex.settings.rental-portal.auto-portal-access-on-signing'), [])
            ->assertSessionHasErrors('auto_portal_access_on_signing');
        $this->assertFalse(RentalPortalSetting::autoPortalAccessOnSigningFor($this->agency->id));

        $this->actingAs($this->agent)->post(route('corex.settings.rental-portal.auto-portal-access-on-signing'), ['auto_portal_access_on_signing' => '1']);
        $this->assertTrue(RentalPortalSetting::autoPortalAccessOnSigningFor($this->agency->id));
    }
}
