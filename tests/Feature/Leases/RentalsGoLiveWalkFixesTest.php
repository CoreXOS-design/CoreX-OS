<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Mail\OtpMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Template;
use App\Models\FicaSubmission;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Rentals\LeaseSigningLauncher;
use App\Services\WebTemplateDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sherry's Friday go-live — the faults cc5's full rentals walk found (13 Oct 2026), items I-O of the brief.
 * One focused test per fault, each failing on the code as it was.
 */
final class RentalsGoLiveWalkFixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cr-' . uniqid(), 'phone' => '021 555 0100', 'email' => 'hello@caperentals.test', 'address' => '12 Long Street, Cape Town']);
        $this->other = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => '2 Bed Flat Sea Point', 'status' => 'to_let', 'listing_type' => 'rental',
            'address' => 'Unit 7, Sea Court, 21 Marine Parade', 'street_number' => '21', 'street_name' => 'Marine Parade',
            'complex_name' => 'Sea Court', 'unit_number' => '7', 'suburb' => 'Sea Point', 'city' => 'Cape Town',
            'rental_amount' => 14500, 'deposit_amount' => 29000,
        ]);
        $this->tenant = $this->contact('Thandi', 'Ngcobo', 'thandi@example.test', '9001015800087');
        $this->landlord = $this->contact('Pieter', 'Botha', 'pieter@example.test', null);
        DB::table('contact_property')->insert([
            'contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contact(string $first, string $last, string $email, ?string $id): Contact
    {
        $c = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '0821234567', 'id_number' => $id,
        ]);
        DB::table('contacts')->where('id', $c->id)->update(['agency_id' => $this->agency->id]);

        return $c->fresh();
    }

    private function application(array $overrides = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'contact_id' => $this->tenant->id, 'created_by_user_id' => $this->agent->id,
            'status' => 'draft', 'current_generation' => 1, 'token' => 'tok-' . uniqid(),
            'token_expires_at' => now()->addDays(14),
        ], $overrides));
    }

    // ── I. the verification-code email is the AGENCY's, never CoreX's ──────────────────

    public function test_an_applicants_verification_email_carries_the_agencys_name_and_details_and_nothing_of_corex(): void
    {
        $mail = new OtpMail('481516', 10, "Verify it's you to complete your rental application", null, $this->agency);
        $html = $mail->render();

        self::assertStringContainsString('Cape Rentals', $html);
        self::assertStringContainsString('12 Long Street, Cape Town', $html);
        self::assertStringContainsString('hello@caperentals.test', $html);
        self::assertStringContainsString('481516', $html);
        self::assertStringNotContainsString('CoreX', $html);
        self::assertStringNotContainsString('Os</span>', $html);

        $env = $mail->envelope();
        self::assertSame('Cape Rentals', $env->from->name);
        self::assertStringContainsString('Cape Rentals', (string) $env->subject);
        self::assertStringNotContainsString('CoreX', (string) $env->subject);
        self::assertSame('hello@caperentals.test', $env->replyTo[0]->address);

        // Staff / platform codes (no agency) stay the CoreX email.
        $staff = new OtpMail('222333');
        self::assertStringContainsString('CoreX', $staff->render());
        self::assertSame('CoreX OS', $staff->envelope()->from->name);
    }

    public function test_the_application_form_names_the_section_it_is_on_and_never_claims_seven_steps(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/rental-applications/public/show.blade.php'));
        self::assertStringNotContainsString('Section ${index} of', $src);
        self::assertStringContainsString('You are on: ${current.dataset.progressSection}', $src);
    }

    public function test_the_fica_form_numbers_the_sections_it_shows_not_hand_typed_numbers(): void
    {
        $submission = FicaSubmission::create([
            'contact_id' => $this->tenant->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'requested_by' => $this->agent->id, 'status' => 'draft',
            'token' => $token = Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);

        $html = $this->get(route('fica.form', $token))->assertOk()->getContent();

        self::assertDoesNotMatchRegularExpression('/fica-section-title">\s*(<span[^>]*><\/span>)?\s*\d+\./', $html, 'no hand-typed section numbers');
        self::assertStringContainsString('counter-increment: ficasec', $html);
        self::assertStringContainsString('counter(ficasec)', $html);
    }

    // ── J. link / change the property on an application captured without one ─────────────

    public function test_an_application_made_without_a_property_can_have_one_linked_later_until_it_is_with_the_authoriser(): void
    {
        $app = $this->application();

        $page = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app))->assertOk();
        $page->assertSee('data-qa="application-property-link"', false);
        $page->assertSee('No property linked yet.');

        $linked = $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.link-property', $app), ['property_id' => $this->property->id]);
        $linked->assertOk()->assertJsonPath('ok', true);
        self::assertStringContainsString('Sea Point', (string) $linked->json('label'));
        self::assertNotNull($linked->json('updated_at'));
        self::assertSame($this->property->id, (int) $app->fresh()->property_id);

        // Change it to another of the agency's rentals, then clear it.
        $second = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'House Camps Bay', 'status' => 'to_let', 'listing_type' => 'rental', 'address' => '5 Victoria Road', 'suburb' => 'Camps Bay',
        ]);
        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.link-property', $app), ['property_id' => $second->id])->assertOk();
        self::assertSame($second->id, (int) $app->fresh()->property_id);
        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.link-property', $app), ['property_id' => null])->assertOk();
        self::assertNull($app->fresh()->property_id);

        // Another agency's property can never be linked.
        $foreign = Property::withoutAgencyStamping(fn () => Property::create([
            'agency_id' => $this->other->id, 'agent_id' => $this->agent->id, 'title' => 'Not ours', 'status' => 'to_let', 'listing_type' => 'rental', 'address' => '1 Elsewhere Rd', 'suburb' => 'Elsewhere',
        ]));
        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.link-property', $app), ['property_id' => $foreign->id])->assertForbidden();
        self::assertNull($app->fresh()->property_id);

        // Once with the authoriser (the agency's own lock), the property is fixed.
        $app->forceFill(['status' => 'under_assessment', 'property_id' => $this->property->id])->save();
        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.link-property', $app), ['property_id' => $second->id])->assertForbidden();
        self::assertSame($this->property->id, (int) $app->fresh()->property_id);
    }

    // ── K. one message, not a toast AND a banner ─────────────────────────────────────

    public function test_the_application_created_message_is_shown_once(): void
    {
        $app = $this->application();

        $html = $this->actingAs($this->agent)->withSession(['success' => 'Rental application created. Review it, then send.'])
            ->get(route('corex.rental-applications.show', $app))->assertOk()->getContent();

        self::assertSame(1, substr_count($html, 'Rental application created. Review it, then send.'), 'toast only — no second inline banner');
    }

    // ── M. e-sign pages ──────────────────────────────────────────────────────────────

    public function test_the_wizard_component_exposes_getRoleLabel_to_its_page_expressions(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/docuperfect/esign/wizard.blade.php'));

        // The helper is a closure inside esignWizard(); the page calls it as a component member — it must be one.
        self::assertMatchesRegularExpression('/\n\s{8}getRoleLabel\(role\) \{\s*\n\s*return getRoleLabel\(role\);\s*\n\s*\},/', $src);
    }

    public function test_a_web_template_with_no_stored_page_images_is_not_asked_for_them(): void
    {
        Storage::fake('local');
        $controller = new ESignWizardController();
        $probe = new \ReflectionMethod($controller, 'templateHasPageImages');
        $probe->setAccessible(true);

        $web = new Template();
        $web->forceFill(['id' => 88, 'page_count' => 6]);
        self::assertFalse($probe->invoke($controller, $web), 'a web template has a page count but no page-N.png: six 404s on every step page');

        Storage::put('docuperfect/templates/88/page-0.png', 'png');
        self::assertTrue($probe->invoke($controller, $web));

        $empty = new Template();
        $empty->forceFill(['id' => 5, 'page_count' => 0]);
        self::assertFalse($probe->invoke($controller, $empty));
    }

    public function test_the_apply_to_remaining_prompt_counts_web_elements_and_hides_itself_at_zero(): void
    {
        $page = (string) file_get_contents(base_path('resources/views/docuperfect/signatures/sign.blade.php'));
        self::assertMatchesRegularExpression('/get remainingSignatureCount\(\) \{\s*if \(this\.isWebTemplate\)/', $page);

        $modal = (string) file_get_contents(base_path('resources/views/docuperfect/signatures/partials/signature-modal.blade.php'));
        self::assertStringContainsString('x-show="showApplyAll && remainingSignatureCount > 0"', $modal);
    }

    public function test_a_sixth_id_check_in_a_minute_gets_a_plain_wait_a_minute_page_not_a_bare_429(): void
    {
        $last = null;
        for ($i = 0; $i < 6; $i++) {
            $last = $this->post('/sign/no-such-token/verify', ['id_number' => '0000000000000']);
        }

        $last->assertStatus(429);
        $last->assertSee('please wait a minute');
        self::assertStringNotContainsString('TOO MANY REQUESTS', $last->getContent());
        self::assertStringNotContainsString('CoreX', $last->getContent());
    }

    // ── N. the QA banner's Mailpit link is a per-environment setting ──────────────────

    public function test_the_banner_mailpit_link_reads_the_environment_setting(): void
    {
        $banner = (string) file_get_contents(base_path('resources/views/partials/_env-banner.blade.php'));
        self::assertStringContainsString("config('mail.mailpit_ui_url')", $banner);
        self::assertStringNotContainsString('href="https://mail.demo1', $banner);
        self::assertSame('https://mail.demo1.corexos.co.za', config('mail.mailpit_ui_url'));
    }

    // ── L. the Who-signs panel knows the property chosen before the screen opened ─────

    public function test_the_lease_screen_hands_the_chosen_property_to_the_signers_panel(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.leases.create', ['property_id' => $this->property->id]))->assertOk()->getContent();

        self::assertStringContainsString('fixedPropertyId\\u0022:' . $this->property->id . ',', $html, 'fixedPropertyId must be the preselected property');
    }

    public function test_the_party_check_names_what_is_missing_for_the_landlord_before_anything_is_pressed(): void
    {
        $json = $this->actingAs($this->agent)->getJson(route('corex.leases.party-check', ['property_id' => $this->property->id, 'tenant_ids' => [$this->tenant->id]]))->assertOk()->json();

        self::assertTrue($json['property_chosen']);
        self::assertSame('Pieter Botha', $json['landlords'][0]['name']);
        self::assertSame(['ID or passport number'], $json['landlords'][0]['needs']);
        self::assertStringContainsString('/contacts/' . $this->landlord->id, $json['landlords'][0]['url']);
        self::assertSame([], $json['tenants'][0]['needs']);
    }

    // ── O. pre-fill everything CoreX already knows ───────────────────────────────────

    public function test_the_lease_flow_carries_the_street_unit_and_complex_not_the_title(): void
    {
        $snapshot = app(LeaseSigningLauncher::class)->propertySnapshot($this->property->fresh());

        self::assertSame('21 Marine Parade', $snapshot['address']);
        self::assertSame('7', $snapshot['unit_number']);
        self::assertSame('Sea Court', $snapshot['complex_name']);
        self::assertSame('Sea Point', $snapshot['suburb']);
        self::assertNotSame('2 Bed Flat Sea Point', $snapshot['address']);

        // …and the agreement's merge fields come out right.
        $resolved = app(WebTemplateDataService::class)->resolve(0, ['property' => $snapshot, 'recipients' => ['recipients' => []], 'details' => []], $this->agent);
        self::assertSame('21 Marine Parade, Sea Point', $resolved['street_address']);
        self::assertSame('21 Marine Parade', $resolved['property_street']);
        self::assertSame('7', $resolved['unit_no']);
        self::assertSame('Sea Court', $resolved['complex_name']);
    }

    public function test_a_tenants_address_comes_from_their_application_when_the_contact_has_none(): void
    {
        $launcher = app(LeaseSigningLauncher::class);
        self::assertSame('', $launcher->addressFor($this->tenant, 'tenant'));

        $this->application(['status' => 'approved', 'current_residential_address' => '8 Kloof Road, Bantry Bay']);
        self::assertSame('8 Kloof Road, Bantry Bay', $launcher->addressFor($this->tenant, 'tenant'));
        self::assertSame('', $launcher->addressFor($this->landlord, 'landlord'), 'a landlord never borrows a tenant application address');

        // A contact's own address always wins; a built one beats nothing.
        DB::table('contacts')->where('id', $this->tenant->id)->update(['address' => '1 Own Street']);
        self::assertSame('1 Own Street', $launcher->addressFor($this->tenant->fresh(), 'tenant'));
        DB::table('contacts')->where('id', $this->landlord->id)->update(['street_number' => '3', 'street_name' => 'Vine Lane', 'suburb' => 'Paarl']);
        self::assertSame('3 Vine Lane, Paarl', $launcher->addressFor($this->landlord->fresh(), 'landlord'));
    }
}
