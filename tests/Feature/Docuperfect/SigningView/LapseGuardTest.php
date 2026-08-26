<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Track C (HD-9/10) — a LAPSED ceremony cannot be signed (§11-A.1).
 *
 * A ceremony has a LEGAL clock (the mandate's expiry) distinct from its 14-day link TTL. A signature
 * collected after the legal date is null and void — so the pen must stop the instant that date
 * passes, independent of the nightly sweeper. SigningController's ~21 signing-guard entry points were
 * swept from `isExpired()` to `isSigningBlocked()` (token-expiry OR template-lapse) in one change, so
 * every one inherits the block; this proves the predicates that sweep now rides on, plus the dispatch
 * stamp that arms the clock.
 */
final class LapseGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($this->agent);
    }

    private function template(?string $deadline, string $status = SignatureTemplate::STATUS_SIGNING): SignatureTemplate
    {
        $doc = Document::create(['name' => 'Sole Mandate', 'owner_id' => $this->agent->id]);

        return SignatureTemplate::create([
            'document_id'       => $doc->id,
            'document_hash'     => Str::random(64),
            'status'            => $status,
            'created_by'        => $this->agent->id,
            'legal_deadline_at' => $deadline,
        ]);
    }

    private function request(SignatureTemplate $t, ?string $tokenExpiry = null): SignatureRequest
    {
        return SignatureRequest::create([
            'signature_template_id' => $t->id,
            'party_role'            => 'seller',
            'role_index'            => 1,
            'signing_order'         => 1,
            'signer_name'           => 'Nomsa Dlamini',
            'signer_email'          => 'nomsa@example.co.za',
            'token'                 => Str::random(48),
            'token_expires_at'      => $tokenExpiry ?? now()->addDays(14),
            'status'                => SignatureRequest::STATUS_PENDING,
        ]);
    }

    // ── isLapsed() ───────────────────────────────────────────────────────────────────────────

    public function test_a_past_legal_deadline_on_a_live_ceremony_is_lapsed(): void
    {
        $this->assertTrue($this->template(now()->subDay()->toDateTimeString())->isLapsed());
    }

    public function test_a_future_legal_deadline_is_not_lapsed(): void
    {
        $this->assertFalse($this->template(now()->addDays(30)->toDateTimeString())->isLapsed());
    }

    /** THE DEFAULT: no legal clock set → never lapses. Every ceremony that predates Track C is here. */
    public function test_no_legal_deadline_never_lapses(): void
    {
        $this->assertFalse($this->template(null)->isLapsed());
    }

    /** A completed ceremony is done — a past date no longer means anything. */
    public function test_a_terminal_ceremony_is_not_lapsed_even_past_its_deadline(): void
    {
        $this->assertFalse(
            $this->template(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_COMPLETED)->isLapsed()
        );
        $this->assertFalse(
            $this->template(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_CANCELLED)->isLapsed()
        );
    }

    /** But a ceremony already recorded as 'lapsed' still reports lapsed — it is not terminal. */
    public function test_a_lapsed_status_still_reports_lapsed(): void
    {
        $this->assertTrue(
            $this->template(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_LAPSED)->isLapsed()
        );
    }

    // ── isSigningBlocked() — the two clocks ──────────────────────────────────────────────────

    public function test_a_lapse_blocks_signing_even_when_the_link_is_fresh(): void
    {
        $t = $this->template(now()->subDay()->toDateTimeString());
        $req = $this->request($t, now()->addDays(14)->toDateTimeString()); // link NOT expired

        $this->assertTrue($req->fresh()->isSigningBlocked(),
            'A past legal deadline must stop the pen even though the 14-day link is still valid.');
    }

    public function test_an_expired_link_still_blocks_when_not_lapsed(): void
    {
        $t = $this->template(now()->addDays(30)->toDateTimeString());  // not lapsed
        $req = $this->request($t, now()->subDay()->toDateTimeString()); // link expired

        $this->assertTrue($req->fresh()->isSigningBlocked(), 'The link-TTL block still works — isExpired() semantics are intact.');
    }

    public function test_a_fresh_link_on_a_live_ceremony_is_not_blocked(): void
    {
        $t = $this->template(now()->addDays(30)->toDateTimeString());
        $req = $this->request($t, now()->addDays(14)->toDateTimeString());

        $this->assertFalse($req->fresh()->isSigningBlocked());
    }

    // ── dispatch arms the clock (HD-9) ───────────────────────────────────────────────────────

    public function test_dispatch_stamps_the_mandate_legal_deadline_from_the_property_expiry(): void
    {
        $property = Property::create([
            'title' => '27 Marine Drive', 'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id, 'listing_type' => 'sale', 'address' => '27 Marine Drive, Ramsgate',
            'expiry_date' => now()->addDays(60)->toDateString(),
        ]);

        $docType = \App\Models\Docuperfect\DocumentType::create(['slug' => 'mandate', 'label' => 'Mandate', 'sort_order' => 0, 'is_active' => true]);
        $tpl = Template::create(['name' => 'Sole Mandate', 'template_type' => 'mandate', 'render_type' => 'web', 'blade_view' => 'x', 'is_esign' => true, 'fields_json' => [], 'document_type_id' => $docType->id]);
        $doc = Document::create(['name' => 'Sole Mandate', 'owner_id' => $this->agent->id, 'template_id' => $tpl->id, 'property_id' => $property->id]);

        $sig = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_DRAFT, 'created_by' => $this->agent->id,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $sig->id, 'party_role' => 'agent', 'role_index' => 1, 'signing_order' => 1,
            'signer_name' => $this->agent->name, 'signer_email' => $this->agent->email, 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14), 'status' => SignatureRequest::STATUS_PENDING,
        ]);

        app(SignatureService::class)->sendForSigning($sig, $this->agent);

        $sig->refresh();
        $this->assertNotNull($sig->legal_deadline_at, 'A mandate ceremony must arm its legal clock at dispatch.');
        $this->assertSame(now()->addDays(60)->toDateString(), $sig->legal_deadline_at->toDateString());
        $this->assertSame('mandate_expiry', $sig->deadline_source);
    }

    /** Absorb, never break: a mandate with no property expiry simply never lapses. */
    public function test_dispatch_leaves_the_deadline_null_when_there_is_no_expiry(): void
    {
        $tpl = Template::create(['name' => 'Sole Mandate', 'template_type' => 'mandate', 'render_type' => 'web', 'blade_view' => 'x', 'is_esign' => true, 'fields_json' => []]);
        $doc = Document::create(['name' => 'Sole Mandate', 'owner_id' => $this->agent->id, 'template_id' => $tpl->id]);
        $sig = SignatureTemplate::create(['document_id' => $doc->id, 'document_hash' => Str::random(64), 'status' => SignatureTemplate::STATUS_DRAFT, 'created_by' => $this->agent->id]);
        SignatureRequest::create([
            'signature_template_id' => $sig->id, 'party_role' => 'agent', 'role_index' => 1, 'signing_order' => 1,
            'signer_name' => $this->agent->name, 'signer_email' => $this->agent->email, 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14), 'status' => SignatureRequest::STATUS_PENDING,
        ]);

        app(SignatureService::class)->sendForSigning($sig, $this->agent);

        $this->assertNull($sig->fresh()->legal_deadline_at);
        $this->assertFalse($sig->fresh()->isLapsed());
    }
}
