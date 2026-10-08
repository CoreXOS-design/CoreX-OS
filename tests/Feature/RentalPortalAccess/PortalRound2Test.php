<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalFaultTypeDocument;
use App\Models\RentalPortalSetting;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalPortalFaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * .ai/specs/rental-portal-access.md §22 — portal round 2 (Johan's testing, 8 Oct 2026): the agency logo on every portal page, the
 * Home FAQ worked out from the lease's own terms, the lease details opening under their lease, and the tenant fault form: one
 * press = one fault, several photos, and the "before you report" content. QA1 only.
 */
final class PortalRound2Test extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-08 09:00:00'));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cr-' . uniqid(), 'logo_path' => 'agency-logos/cape.png']);
        $this->other = Agency::create(['name' => 'Other Lettings', 'slug' => 'ol-' . uniqid(), 'logo_path' => null]);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        Branch::create(['agency_id' => $this->other->id, 'name' => 'Main', 'code' => 'M-' . $this->other->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => Branch::where('agency_id', $this->agency->id)->value('id'),
            'title' => '12 Marine Drive', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => '2026-06-01', 'end_date' => '2027-05-31',
            'is_month_to_month' => false, 'lease_type' => 'residential', 'source' => 'manual', 'signing_status' => 'signed_on_paper',
        ]);
        $this->tenant = $this->contact($this->agency, 'Tina');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = $this->contact($this->agency, 'Lenny');
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);

        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Geyser', 'category' => 'Plumbing', 'urgency' => 'urgent',
            'first_aid_steps' => "Switch the geyser off at the DB board.\nDo not open the cover.", 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function contact(Agency $agency, string $first): Contact
    {
        return Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'branch_id' => Branch::where('agency_id', $agency->id)->value('id'), 'first_name' => $first, 'last_name' => 'Test', 'email' => strtolower($first) . '+' . uniqid() . '@example.test',
        ]);
    }

    private function login(Contact $c): ClientUser
    {
        $cu = $c->client_user_id ? ClientUser::find($c->client_user_id) : ClientUser::create(['email' => $c->email, 'current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();
        Sanctum::actingAs($cu, ['client']);

        return $cu;
    }

    private function terms(array $attrs, ?Lease $lease = null): LeaseAgreementTerms
    {
        $lease ??= $this->lease;

        return LeaseAgreementTerms::withoutGlobalScopes()->create($attrs + ['notice_terms_confirmed_at' => now(), 'agency_id' => $lease->agency_id, 'lease_id' => $lease->id]);
    }

    private function faqFor(Contact $c, string $audience = 'tenant'): array
    {
        $this->login($c);
        $url = $audience === 'landlord' ? '/api/v1/client/rentals/landlord/overview' : '/api/v1/client/rentals/overview';

        return $this->getJson($url)->assertOk()->json('homes.0.faq');
    }

    private function photo(string $name = 'a.jpg', int $kb = 0): UploadedFile
    {
        $f = UploadedFile::fake()->image($name, 200, 200);

        return $kb > 0 ? $f->size($kb) : $f;
    }

    private function reportUrl(): string
    {
        return '/api/v1/client/rentals/properties/' . $this->property->id . '/fault-reports';
    }

    private function faultFields(array $over = []): array
    {
        return $over + [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem',
            'title' => 'Geyser dripping', 'description' => 'Water on the floor',
        ];
    }

    // ── P1 · the agency logo on every portal page ───────────────────────────

    public function test_the_branding_endpoint_returns_the_signed_in_persons_agency_logo_and_name(): void
    {
        $this->login($this->tenant);

        $b = $this->getJson('/api/v1/client/rentals/branding')->assertOk()->json('branding');

        $this->assertSame('Cape Rentals', $b['name']);
        $this->assertStringEndsWith('storage/agency-logos/cape.png', $b['logo_url']);
    }

    public function test_the_branding_falls_back_to_the_agency_name_when_there_is_no_logo(): void
    {
        $this->agency->update(['logo_path' => null]);
        $this->login($this->tenant);

        $b = $this->getJson('/api/v1/client/rentals/branding')->assertOk()->json('branding');

        $this->assertNull($b['logo_url']);
        $this->assertSame('Cape Rentals', $b['name']);
    }

    public function test_an_owner_gets_their_own_agencys_branding_too(): void
    {
        $this->login($this->landlord);

        $this->assertSame('Cape Rentals', $this->getJson('/api/v1/client/rentals/branding')->assertOk()->json('branding.name'));
    }

    public function test_the_branding_needs_a_portal_login(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost'])->getJson('/api/v1/client/rentals/branding')->assertStatus(401);
    }

    public function test_the_shell_knows_the_agency_from_a_personal_link_email_before_sign_in(): void
    {
        $this->login($this->tenant);
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $html = $this->get('/portal?email=' . urlencode($this->tenant->email))->assertOk()->getContent();

        $this->assertStringContainsString('data-portal-brand', $html);
        $this->assertStringContainsString('data-portal-logo', $html);
        $this->assertStringContainsString('data-portal-name', $html);
        $this->assertStringContainsString('agency-logos\/cape.png', $html, 'the logo URL is in the page for the header');
        $this->assertStringContainsString('Cape Rentals', $html);
    }

    public function test_the_shell_shows_no_agency_for_an_unknown_email_or_no_email(): void
    {
        $none = $this->get('/portal')->assertOk()->getContent();
        $unknown = $this->get('/portal?email=' . urlencode('nobody@example.test'))->assertOk()->getContent();
        $junk = $this->get('/portal?email=' . urlencode('not an email'))->assertOk()->getContent();

        foreach ([$none, $unknown, $junk] as $html) {
            $this->assertStringContainsString('branding: null', $html);
            $this->assertStringNotContainsString('Cape Rentals', $html);
        }
    }

    public function test_an_email_on_two_agencies_shows_no_agency_before_sign_in(): void
    {
        $email = 'shared+' . uniqid() . '@example.test';
        foreach ([$this->agency, $this->other] as $a) {
            $c = $this->contact($a, 'Twin');
            $c->forceFill(['email' => $email, 'client_user_id' => ClientUser::create(['email' => $email . $a->id, 'current_agency_id' => $a->id])->id])->saveQuietly();
        }

        $html = $this->get('/portal?email=' . urlencode($email))->assertOk()->getContent();

        $this->assertStringContainsString('branding: null', $html);
    }

    // ── P2 · the Home FAQ from the lease's own terms ────────────────────────

    public function test_unconfirmed_terms_are_never_stated_to_the_tenant_or_the_owner(): void
    {
        $this->terms(['notice_period' => 30, 'notice_period_unit' => 'days', 'early_cancellation_allowed' => 'yes', 'notice_terms_source' => 'agency_default', 'notice_terms_confirmed_at' => null]);

        $this->assertSame([], $this->faqFor($this->tenant), 'agency-default terms: the portal says nothing about notice');
        $this->assertSame([], $this->faqFor($this->landlord, 'landlord'));

        LeaseAgreementTerms::withoutGlobalScopes()->where('lease_id', $this->lease->id)->update(['notice_terms_confirmed_at' => now()]);
        $this->assertNotEmpty($this->faqFor($this->tenant), 'once an agent confirms them');
    }

    public function test_a_lease_with_an_earliest_termination_date_and_a_notice_period_gets_both_answers(): void
    {
        $this->terms(['earliest_termination_date' => '2027-02-28']);
        LeaseSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'tenant_notice_period_days' => 60]);

        $faq = $this->faqFor($this->tenant);

        $this->assertSame(['notice', 'early'], array_column($faq, 'key'));
        $this->assertSame('Can I give notice?', $faq[0]['question']);
        $this->assertSame('What happens if I give notice before my lease expires?', $faq[1]['question']);
        $a1 = $faq[0]['answer'];
        $this->assertStringContainsString('at least 60 days', $a1);
        $this->assertStringContainsString('cannot end before 28 Feb 2027', $a1);
        $this->assertStringContainsString('You can give notice from 30 Dec 2026', $a1, '28 Feb 2027 less 60 days');
        $this->assertStringContainsString('runs until 31 May 2027', $a1);
        $this->assertStringContainsString('give notice by 1 Apr 2027', $a1, '31 May 2027 less 60 days');
        $this->assertStringContainsString('28 Feb 2027', $faq[1]['answer']);
        $this->assertStringContainsString('under Documents', $faq[1]['answer']);
        foreach ($faq as $q) {
            $this->assertStringNotContainsString('{', $q['answer'], 'no raw token ever reaches the page');
            $this->assertStringNotContainsString('[[', $q['answer']);
        }
    }

    public function test_a_lease_without_any_notice_or_cancellation_terms_shows_no_faq_at_all(): void
    {
        // The agency has a standard notice period, but the LEASE says nothing: nothing is shown rather than a guess.
        LeaseSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'tenant_notice_period_days' => 30]);

        $this->assertSame([], $this->faqFor($this->tenant));

        // an empty terms row says nothing either
        $this->terms(['adults' => 2]);
        $this->assertSame([], $this->faqFor($this->tenant));
    }

    public function test_the_home_still_reports_the_start_and_end_and_no_longer_prints_the_notice_period_line(): void
    {
        $this->login($this->tenant);
        $home = $this->getJson('/api/v1/client/rentals/overview')->assertOk()->json('homes.0');
        $this->assertSame('2026-06-01', $home['tenancy']['start_date']);
        $this->assertSame('2027-05-31', $home['tenancy']['end_date']);
        $this->assertArrayHasKey('notice_period_days', $home['tenancy'], 'kept in the payload for the mobile app');

        $panel = file_get_contents(resource_path('views/rentals/portal/_home.blade.php'));
        $this->assertStringNotContainsString('Notice period', $panel, 'the notice-period line is gone from the Home');
        $this->assertStringContainsString('data-portal-home-faq', $panel);
        $this->assertStringContainsString('h.contact.agent', $panel, 'agent details stay');
        $this->assertStringContainsString('h.tenancy.start_date', $panel);
        $this->assertStringContainsString('h.tenancy.end_date', $panel);
    }

    public function test_without_a_saved_agency_notice_period_no_length_is_stated(): void
    {
        $this->terms(['earliest_termination_date' => '2027-02-28']); // no LeaseSetting row at all

        $faq = $this->faqFor($this->tenant);

        $this->assertStringNotContainsString('days before', $faq[0]['answer'], 'the 30-day fallback is a default, not a term');
        $this->assertStringNotContainsString('You can give notice', $faq[0]['answer'], 'cannot be worked out without a length');
        $this->assertStringContainsString('cannot end before 28 Feb 2027', $faq[0]['answer']);
    }

    public function test_an_earliest_notice_date_that_has_already_passed_reads_now(): void
    {
        $this->terms(['earliest_termination_date' => '2026-11-15']);
        LeaseSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'tenant_notice_period_days' => 60]); // 15 Nov - 60d = 16 Sep: behind us

        $faq = $this->faqFor($this->tenant);

        $this->assertStringContainsString('You can give notice now.', $faq[0]['answer'], '16 Sep 2026 is behind us: it is simply "now", never a past date');
        $this->assertStringNotContainsString('16 Sep 2026', $faq[0]['answer']);
        $this->assertStringContainsString('cannot end before 15 Nov 2026', $faq[0]['answer']);
    }

    public function test_the_leases_own_notice_length_and_cancellation_terms_win_over_the_agency_standard(): void
    {
        $this->terms(['extra' => ['notice_period_days' => 45, 'early_cancellation_terms' => 'An early-cancellation fee of two months rent applies.']]);
        LeaseSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'tenant_notice_period_days' => 60]);

        $faq = $this->faqFor($this->tenant);

        $this->assertStringContainsString('at least 45 days', $faq[0]['answer']);
        $this->assertStringContainsString('An early-cancellation fee of two months rent applies.', $faq[1]['answer']);
    }

    public function test_the_agencys_own_faq_wording_is_used_with_the_lease_values_merged_in(): void
    {
        $this->terms(['earliest_termination_date' => '2027-02-28']);
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'faq_tenant_notice_question' => 'Mag ek kennis gee?',
            'faq_tenant_notice_answer' => 'Ja. Eerste vervaldatum: {earliest_termination_date}.[[ Kennisgewing: {notice_days} dae.]] {unknown_thing}',
        ]);

        $faq = $this->faqFor($this->tenant);

        $this->assertSame('Mag ek kennis gee?', $faq[0]['question']);
        $this->assertSame('Ja. Eerste vervaldatum: 28 Feb 2027.', $faq[0]['answer'], 'the notice-days piece is dropped whole, the unknown token removed');
    }

    public function test_an_answer_that_ends_up_empty_is_dropped_with_its_question(): void
    {
        $this->terms(['earliest_termination_date' => '2027-02-28']);
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['faq_tenant_notice_answer' => '[[Notice days: {notice_days}]]']);

        $faq = $this->faqFor($this->tenant);

        $this->assertSame(['early'], array_column($faq, 'key'));
    }

    public function test_the_owner_reads_the_owner_wording(): void
    {
        $this->terms(['earliest_termination_date' => '2027-02-28']);

        $faq = $this->faqFor($this->landlord, 'landlord');

        $this->assertSame('Can the tenant give notice?', $faq[0]['question']);
        $this->assertSame('What happens if the tenant gives notice before the lease expires?', $faq[1]['question']);
        $this->assertStringContainsString('The lease cannot end before 28 Feb 2027', $faq[0]['answer']);
        $this->assertStringContainsString('signed lease under Documents', $faq[1]['answer']);
    }

    public function test_an_expired_or_cancelled_lease_gets_no_faq(): void
    {
        $svc = app(RentalPortalFaqService::class);
        $this->terms(['earliest_termination_date' => '2027-02-28']);
        foreach ([Lease::STATUS_EXPIRED, Lease::STATUS_CANCELLED, Lease::STATUS_DRAFT] as $status) {
            $this->lease->forceFill(['status' => $status])->save();
            $this->assertSame([], $svc->forLease($this->lease->fresh(), 'tenant'), $status);
        }
    }

    public function test_the_template_engine_drops_pieces_whose_value_is_missing_and_tidies_the_gaps(): void
    {
        $svc = app(RentalPortalFaqService::class);
        $v = ['notice_days' => '30', 'earliest_termination_date' => null, 'earliest_notice_date' => null, 'lease_end_date' => '1 Jan 2028', 'early_cancellation_terms' => null];

        $this->assertSame('Yes, in writing, 30 days before.', $svc->render('Yes, in writing[[, {notice_days} days before]].[[ Not before {earliest_termination_date}.]]', $v));
        $this->assertSame('Ends 1 Jan 2028.', $svc->render('Ends {lease_end_date}.', $v));
        $this->assertSame('', $svc->render('[[Only {early_cancellation_terms}]]', $v));
    }

    // ── P4c · what the person sees when they pick a fault type ─────────────

    public function test_picking_a_fault_type_brings_the_steps_urgency_valve_photo_and_the_agencys_documents(): void
    {
        $this->property->forceFill(['rental_main_water_valve_photo_path' => '/storage/properties/1/valve.jpg'])->save();
        Storage::disk('public')->put('rental-fault-type-images/1/diagram.png', 'x');
        Storage::disk('local')->put('rental-fault-type-documents/1/guide.pdf', 'pdf');
        RentalFaultTypeDocument::create(['agency_id' => $this->agency->id, 'rental_fault_type_id' => $this->faultType->id, 'document_type' => 'image', 'storage_path' => 'rental-fault-type-images/1/diagram.png', 'caption' => 'Where the switch is', 'sort_order' => 1]);
        RentalFaultTypeDocument::create(['agency_id' => $this->agency->id, 'rental_fault_type_id' => $this->faultType->id, 'document_type' => 'video_link', 'external_url' => 'https://youtu.be/abc', 'caption' => 'Reset the geyser', 'sort_order' => 2]);
        RentalFaultTypeDocument::create(['agency_id' => $this->agency->id, 'rental_fault_type_id' => $this->faultType->id, 'document_type' => 'video_link', 'external_url' => 'javascript:alert(1)', 'caption' => 'Bad', 'sort_order' => 3]);
        $pdf = RentalFaultTypeDocument::create(['agency_id' => $this->agency->id, 'rental_fault_type_id' => $this->faultType->id, 'document_type' => 'pdf', 'storage_path' => 'rental-fault-type-documents/1/guide.pdf', 'caption' => 'Geyser guide', 'sort_order' => 4]);
        $this->login($this->tenant);

        $res = $this->getJson('/api/v1/client/rentals/properties/' . $this->property->id . '/fault-types')->assertOk();

        $type = collect($res->json('fault_types'))->firstWhere('id', $this->faultType->id);
        $this->assertSame('urgent', $type['urgency']);
        $this->assertStringContainsString('Switch the geyser off', $type['first_aid_steps']);
        $this->assertSame('Main water valve', $type['photos'][0]['label']);
        $this->assertCount(3, $type['documents'], 'the unsafe javascript: link is never handed to a phone');
        $this->assertSame('image', $type['documents'][0]['type']);
        $this->assertStringContainsString('storage/rental-fault-type-images/1/diagram.png', $type['documents'][0]['url']);
        $this->assertSame('https://youtu.be/abc', $type['documents'][1]['url']);
        $this->assertStringContainsString('/fault-type-documents/' . $pdf->id . '/file', $type['documents'][2]['url']);
        $this->assertSame(['max_photos' => 6, 'max_photo_mb' => 8], $res->json('limits'));

        // The gated document download: the person's own agency's active fault type only.
        $this->get('/api/v1/client/rentals/fault-type-documents/' . $pdf->id . '/file')->assertOk();
    }

    public function test_a_fault_type_document_is_not_served_across_agencies_or_for_a_retired_type(): void
    {
        Storage::disk('local')->put('rental-fault-type-documents/9/guide.pdf', 'pdf');
        $foreignType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->other->id, 'name' => 'Other', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_active' => true, 'sort_order' => 1]);
        $foreign = RentalFaultTypeDocument::create(['agency_id' => $this->other->id, 'rental_fault_type_id' => $foreignType->id, 'document_type' => 'pdf', 'storage_path' => 'rental-fault-type-documents/9/guide.pdf', 'sort_order' => 1]);
        $retired = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Old', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_active' => false, 'sort_order' => 2]);
        $old = RentalFaultTypeDocument::create(['agency_id' => $this->agency->id, 'rental_fault_type_id' => $retired->id, 'document_type' => 'pdf', 'storage_path' => 'rental-fault-type-documents/9/guide.pdf', 'sort_order' => 1]);
        $this->login($this->tenant);

        $this->getJson('/api/v1/client/rentals/fault-type-documents/' . $foreign->id . '/file')->assertStatus(404);
        $this->getJson('/api/v1/client/rentals/fault-type-documents/' . $old->id . '/file')->assertStatus(404);
    }

    public function test_the_owner_picker_reads_the_same_view(): void
    {
        $this->login($this->landlord);

        $res = $this->getJson('/api/v1/client/rentals/landlord/properties/' . $this->property->id . '/fault-types')->assertOk();

        $type = collect($res->json('fault_types'))->firstWhere('id', $this->faultType->id);
        $this->assertSame('urgent', $type['urgency']);
        $this->assertIsArray($type['documents']);
        $this->assertIsArray($type['photos']);
        $this->assertSame(6, $res->json('limits.max_photos'));
    }

    // ── P4a · one press, one fault ──────────────────────────────────────────

    public function test_pressing_submit_twice_with_the_same_key_makes_one_fault_and_replays_the_answer(): void
    {
        $this->login($this->tenant);
        $headers = ['X-Submission-Key' => 'key-double-tap-0001'];

        $first = $this->withHeaders($headers)->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);
        $second = $this->withHeaders($headers)->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);

        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->where('reported_by_contact_id', $this->tenant->id)->count());
        $this->assertSame($first->json('fault_report.id'), $second->json('fault_report.id'));
        $this->assertSame('1', $second->headers->get('X-Portal-Replay'));
        $this->assertNull($first->headers->get('X-Portal-Replay'));
    }

    public function test_the_same_key_as_a_form_field_works_as_well_as_the_header(): void
    {
        $this->login($this->tenant);

        $this->postJson($this->reportUrl(), $this->faultFields(['submission_key' => 'field-key-000001']))->assertStatus(201);
        $this->postJson($this->reportUrl(), $this->faultFields(['submission_key' => 'field-key-000001']))->assertStatus(201);

        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_a_client_that_sends_no_key_is_still_guarded_by_the_content_of_the_request(): void
    {
        $this->login($this->tenant);

        $a = $this->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);
        $b = $this->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);

        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->count());
        $this->assertSame($a->json('fault_report.id'), $b->json('fault_report.id'));
    }

    public function test_the_same_words_twenty_seconds_later_are_a_new_report(): void
    {
        $this->login($this->tenant);
        $this->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);

        $this->travel(30)->seconds();
        $this->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);

        $this->assertSame(2, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_two_different_keys_are_two_genuine_reports(): void
    {
        $this->login($this->tenant);

        $this->withHeaders(['X-Submission-Key' => 'key-first-fault-01'])->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);
        $this->withHeaders(['X-Submission-Key' => 'key-other-fault-02'])->postJson($this->reportUrl(), $this->faultFields(['title' => 'Different fault']))->assertStatus(201);

        $this->assertSame(2, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_a_refused_attempt_does_not_lock_the_key_the_person_can_try_again(): void
    {
        $this->login($this->tenant);
        $headers = ['X-Submission-Key' => 'key-retry-after-422'];

        $this->withHeaders($headers)->postJson($this->reportUrl(), ['rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem'])->assertStatus(422);
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count());

        $this->withHeaders($headers)->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);
        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->count());
        $this->assertSame(['failed' => 0, 'done' => 1], [
            'failed' => DB::table('portal_submissions')->where('status', 'failed')->count(),
            'done' => DB::table('portal_submissions')->where('status', 'done')->count(),
        ], 'one ledger row, now done — nothing is ever deleted');
    }

    public function test_an_attempt_that_crashed_long_ago_is_taken_over(): void
    {
        $cu = $this->login($this->tenant);
        DB::table('portal_submissions')->insert([
            'client_user_id' => $cu->id, 'agency_id' => $this->agency->id,
            'request_hash' => sha1('POST ' . ltrim($this->reportUrl(), '/')), 'submission_key' => 'key-crashed-000001',
            'status' => 'processing', 'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5),
        ]);

        $this->withHeaders(['X-Submission-Key' => 'key-crashed-000001'])->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);

        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_two_people_using_the_same_key_do_not_collide(): void
    {
        $this->login($this->tenant);
        $this->withHeaders(['X-Submission-Key' => 'shared-key-000001'])->postJson($this->reportUrl(), $this->faultFields())->assertStatus(201);

        $second = $this->contact($this->agency, 'Sam');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $second->id, 'is_primary' => false]);
        $this->login($second);
        $res = $this->withHeaders(['X-Submission-Key' => 'shared-key-000001'])->postJson($this->reportUrl(), $this->faultFields(['title' => 'Sam reports']))->assertStatus(201);

        $this->assertNull($res->headers->get('X-Portal-Replay'));
        $this->assertSame(2, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_the_owners_request_work_form_is_guarded_the_same_way(): void
    {
        $this->login($this->landlord);
        $url = '/api/v1/client/rentals/landlord/properties/' . $this->property->id . '/fault-reports';
        $h = ['X-Submission-Key' => 'owner-request-0001'];

        $a = $this->withHeaders($h)->postJson($url, ['title' => 'Gate motor', 'description' => 'Stuck'])->assertStatus(201);
        $b = $this->withHeaders($h)->postJson($url, ['title' => 'Gate motor', 'description' => 'Stuck'])->assertStatus(201);

        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->where('title', 'Gate motor')->count());
        $this->assertSame($a->json('fault_report.id'), $b->json('fault_report.id'));
    }

    public function test_an_owner_decision_pressed_twice_is_recorded_once(): void
    {
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP, 'title' => 'Roof leak', 'description' => 'Leaking',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);
        $fault->requestApproval($this->agent);
        $this->login($this->landlord);
        $url = '/api/v1/client/rentals/landlord/fault-reports/' . $fault->id . '/decision';
        $h = ['X-Submission-Key' => 'decide-fault-' . $fault->id . '-approve_agency_appoints'];

        $this->withHeaders($h)->postJson($url, ['decision' => 'approve_agency_appoints'])->assertOk();
        $before = $fault->approvals()->count();
        $this->withHeaders($h)->postJson($url, ['decision' => 'approve_agency_appoints'])->assertOk();

        $this->assertSame($before, $fault->approvals()->count(), 'the second press recorded nothing');
    }

    public function test_get_requests_never_touch_the_ledger(): void
    {
        $this->login($this->tenant);
        $this->getJson('/api/v1/client/rentals/overview')->assertOk();
        $this->getJson('/api/v1/client/rentals/branding')->assertOk();

        $this->assertSame(0, DB::table('portal_submissions')->count());
    }

    // ── P4b · several photos ────────────────────────────────────────────────

    public function test_a_report_can_carry_several_photos(): void
    {
        $this->login($this->tenant);

        $res = $this->post($this->reportUrl(), $this->faultFields() + ['photos' => [$this->photo('one.jpg'), $this->photo('two.jpg'), $this->photo('three.jpg')]], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertSame(3, DB::table('rental_fault_report_photos')->where('rental_fault_report_id', $res->json('fault_report.id'))->count());
    }

    public function test_more_photos_than_the_agency_allows_is_refused_with_a_plain_message_and_nothing_is_saved(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['fault_photo_max_count' => 2]);
        $this->login($this->tenant);

        $res = $this->post($this->reportUrl(), $this->faultFields() + ['photos' => [$this->photo('1.jpg'), $this->photo('2.jpg'), $this->photo('3.jpg')]], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertStringContainsString('up to 2 photos', $res->json('message'));
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('rental_fault_report_photos')->count());
    }

    public function test_a_photo_larger_than_the_agency_limit_is_refused(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['fault_photo_max_mb' => 1]);
        $this->login($this->tenant);

        $res = $this->post($this->reportUrl(), $this->faultFields() + ['photos' => [$this->photo('big.jpg', 2048)]], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertStringContainsString('larger than 1 MB', $res->json('message'));
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_the_limits_reach_the_page_with_the_fault_types(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['fault_photo_max_count' => 4, 'fault_photo_max_mb' => 3]);
        $this->login($this->tenant);

        $this->getJson('/api/v1/client/rentals/properties/' . $this->property->id . '/fault-types')
            ->assertOk()->assertJsonPath('limits.max_photos', 4)->assertJsonPath('limits.max_photo_mb', 3);
    }

    public function test_the_owner_form_obeys_the_same_photo_limit(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['fault_photo_max_count' => 1]);
        $this->login($this->landlord);

        $this->post('/api/v1/client/rentals/landlord/properties/' . $this->property->id . '/fault-reports',
            ['title' => 'Gate', 'photos' => [$this->photo('1.jpg'), $this->photo('2.jpg')]], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ── agency settings (savers are has()-guarded — onboarding §6.1) ────────

    private function staff(): void
    {
        foreach (['rental_portal.manage_settings'] as $key) {
            \App\Models\RolePermission::create(['role' => $this->agent->role, 'permission_key' => $key, 'scope' => 'agency']);
        }
        \App\Services\PermissionService::clearCache();
        $this->actingAs($this->agent);
    }

    public function test_the_faq_saver_changes_only_the_fields_it_was_given_and_blank_returns_to_the_default(): void
    {
        $this->staff();
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['faq_landlord_notice_question' => 'Keep me']);

        $this->post('/corex/settings/rental-portal/faq-texts', ['faq_tenant_notice_question' => 'Can I leave?'])->assertRedirect();
        $this->assertSame('Can I leave?', RentalPortalSetting::faqTextFor($this->agency->id, 'faq_tenant_notice_question'));
        $this->assertSame('Keep me', RentalPortalSetting::faqTextFor($this->agency->id, 'faq_landlord_notice_question'), 'a field the post did not carry is left alone');

        $this->post('/corex/settings/rental-portal/faq-texts', ['faq_tenant_notice_question' => '  '])->assertRedirect();
        $this->assertSame('Can I give notice?', RentalPortalSetting::faqTextFor($this->agency->id, 'faq_tenant_notice_question'), 'blank = the default wording');

        $this->post('/corex/settings/rental-portal/faq-texts', [])->assertRedirect();
        $this->assertSame('Keep me', RentalPortalSetting::faqTextFor($this->agency->id, 'faq_landlord_notice_question'));
    }

    public function test_the_photo_limit_savers_are_bounded_and_leave_alone_when_absent(): void
    {
        $this->staff();

        $this->post('/corex/settings/rental-portal/fault-photo-max-count', ['fault_photo_max_count' => 8])->assertRedirect();
        $this->assertSame(8, RentalPortalSetting::faultPhotoMaxCountFor($this->agency->id));
        $this->post('/corex/settings/rental-portal/fault-photo-max-count', ['fault_photo_max_count' => 99])->assertSessionHasErrors('fault_photo_max_count');
        $this->post('/corex/settings/rental-portal/fault-photo-max-count', [])->assertRedirect();
        $this->assertSame(8, RentalPortalSetting::faultPhotoMaxCountFor($this->agency->id));

        $this->post('/corex/settings/rental-portal/fault-photo-max-mb', ['fault_photo_max_mb' => 4])->assertRedirect();
        $this->assertSame(4, RentalPortalSetting::faultPhotoMaxMbFor($this->agency->id));
        $this->assertSame(6, RentalPortalSetting::faultPhotoMaxCountFor($this->other->id), 'another agency keeps the defaults');
    }

    public function test_the_settings_page_and_the_setup_wizard_carry_the_new_controls(): void
    {
        $this->staff();
        $page = $this->get('/corex/settings/rental-portal')->assertOk()->getContent();
        $this->assertStringContainsString('fault_photo_max_count', $page);
        $this->assertStringContainsString('faq_tenant_notice_answer', $page);
        $this->assertStringContainsString('faq_landlord_early_question', $page);

        $controls = collect(config('agency-onboarding-copy.steps') ?? [])->flatMap(fn ($s) => $s['controls'] ?? [])->pluck('key')->all();
        if (!$controls) {
            // the config nests the steps differently — find every control key anywhere in it
            $controls = [];
            $all = config('agency-onboarding-copy');
            array_walk_recursive($all, function ($v, $k) use (&$controls) { if ($k === 'key') { $controls[] = $v; } });
        }
        foreach (['fault_photo_max_count', 'fault_photo_max_mb', 'faq_tenant_notice_question', 'faq_tenant_notice_answer', 'faq_tenant_early_question', 'faq_tenant_early_answer', 'faq_landlord_notice_question', 'faq_landlord_notice_answer', 'faq_landlord_early_question', 'faq_landlord_early_answer'] as $key) {
            $this->assertContains($key, $controls, "{$key} reaches the Setup Wizard (non-negotiable #10a)");
        }
    }

    // ── P3 + the page itself (markup guards; the browser logic is tests/js/portal-shell.test.mjs) ──

    public function test_the_page_has_the_inline_lease_details_the_photo_picker_and_the_one_press_guards(): void
    {
        $html = $this->get('/portal')->assertOk()->getContent();

        // P3 — details sit INSIDE the lease card they belong to, not in a separate card after the list.
        $card = strpos($html, 'data-lease-card');
        $detail = strpos($html, 'data-lease-detail');
        $this->assertNotFalse($card);
        $this->assertGreaterThan($card, $detail, 'the detail block comes after the card opens...');
        $this->assertStringContainsString('toggleLeaseDetail(lease.id)', $html);
        $this->assertStringNotContainsString('loadLeaseDetail(lease.id)', $html, 'the old "details appear somewhere else" link is gone');
        $this->assertStringContainsString('Hide details', $html);

        // P4b — camera and gallery are separate controls, a pick adds, previews with remove.
        $this->assertStringContainsString('data-photo-camera', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('multiple', $html);
        $this->assertStringContainsString('data-photo-thumb', $html);
        $this->assertStringContainsString('removePhoto(', $html);
        $this->assertStringContainsString('compressPhoto', $html);

        // P4a — buttons go off while sending, with visible progress, a done state and a submission key.
        $this->assertStringContainsString("faultWizard.sending || faultWizard.photoBusy", $html);
        $this->assertStringContainsString('data-fault-done', $html);
        $this->assertStringContainsString("form.append('submission_key'", $html);
        $this->assertStringContainsString('portalUpload(', $html);

        // P4c — the "before you report" content is in the flow between the type and the form.
        $this->assertStringContainsString('data-fault-aid', $html);
        $this->assertStringContainsString('Try this first', $html);

        // P1 — the brand header.
        $this->assertStringContainsString('data-portal-brand', $html);
    }
}
