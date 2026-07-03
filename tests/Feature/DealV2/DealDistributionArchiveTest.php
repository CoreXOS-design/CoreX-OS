<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\User;
use App\Services\Communications\CommsAccessGrantService;
use App\Services\DealV2\DealDistributionService;
use App\Services\DealV2\DealDocumentService;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WS5 (AT-158 / DR2, §10) — one send, three pillars.
 *
 * The distribution write-side (WS4) already stamps the owner, records the pack
 * as a communication_attachment, and writes one communication_link per pillar
 * (Contact + Property + DealV2). WS5 proves the READ surfaces render it:
 * a single distribution surfaces in the Communication Archive AND on the deal
 * AND on the property AND on the recipient contact — with the attachment
 * recorded and the sending agent as owner (§10 verification gate).
 */
final class DealDistributionArchiveTest extends TestCase
{
    use RefreshDatabase;

    private DealDistributionService $dist;
    private DealPipelineService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // The property show page renders through the corex layout, which loads
        // assets via @vite — no built manifest exists under `php artisan test`.
        // withoutVite() swaps in a no-op tag builder so the full page renders.
        $this->withoutVite();
        $this->dist = app(DealDistributionService::class);
        $this->engine = app(DealPipelineService::class);
    }

    // ── Gate: one distribution to a contact appears on all three pillars ────

    public function test_one_distribution_surfaces_on_deal_property_contact_and_archive(): void
    {
        Mail::fake();
        $ctx  = $this->scaffold();
        $deal = $ctx['deal'];

        // Send the OTP to the seller by direct attachment (so the pack file is
        // recorded as a communication_attachment — the strongest gate variant).
        $doc = $this->docOnDeal($ctx, $ctx['otpType']->id, 'OTP-8-Marine-Drive.pdf');
        $recipient = [
            'type'  => 'contact',
            'model' => $ctx['seller'],
            'name'  => $ctx['seller']->full_name,
            'email' => $ctx['seller']->email,
        ];

        $distribution = $this->dist->send($deal, $doc, 'seller', 'direct_attachment', $recipient, $ctx['agent']);

        $comm = Communication::find($distribution->communication_id);
        $this->assertNotNull($comm, 'distribution archived a communication');

        // ── owner + attachment recorded (the "with attachment/link + agent owner" clause)
        $this->assertSame($ctx['agent']->id, $comm->owner_user_id, 'sending agent is the owner');
        $this->assertTrue((bool) $comm->has_attachments, 'attachment flagged');
        $this->assertSame(1, $comm->attachments()->count(), 'attachment row written');

        // ── PILLAR 1: the DEAL — the distributions relation carries it, linked to the comm
        $deal->refresh();
        $this->assertTrue(
            $deal->distributions->contains(fn ($d) => $d->id === $distribution->id),
            'distribution shows on the deal'
        );
        $this->assertcontainsMorph(DealV2::class, $ctx['deal']->id, $comm, 'deal pillar linked');

        // ── PILLAR 2: the PROPERTY — the exact query the property show surface runs
        $propComms = Communication::query()
            ->notPurged()
            ->whereHas('links', function ($q) use ($ctx) {
                $q->where('linkable_type', Property::class)
                  ->where('linkable_id', $ctx['property']->id);
            })
            ->visibleTo($ctx['agent'], 'own')
            ->get();
        $this->assertTrue($propComms->contains('id', $comm->id), 'distribution shows on the property (query)');

        // ── PILLAR 3: the recipient CONTACT — the exact query the contact tab runs
        $contactComms = Communication::query()
            ->whereNull('purged_at')
            ->whereHas('links', function ($q) use ($ctx) {
                $q->where('linkable_type', Contact::class)
                  ->where('linkable_id', $ctx['seller']->id);
            })
            ->get();
        $this->assertTrue($contactComms->contains('id', $comm->id), 'distribution shows on the recipient contact');

        // ── The global Communication Archive — visible to the sending agent
        $archiveVisible = app(CommsAccessGrantService::class)
            ->applyArchiveVisibility(Communication::query()->notPurged(), $ctx['agent'])
            ->pluck('id')->all();
        $this->assertContains($comm->id, $archiveVisible, 'distribution visible in the archive to its owner');
    }

    // ── The property SHOW page actually renders the distribution ────────────

    public function test_property_show_page_renders_the_distribution(): void
    {
        Mail::fake();
        $ctx  = $this->scaffold();

        $doc = $this->docOnDeal($ctx, $ctx['otpType']->id, 'FICA-pack.pdf');
        $recipient = [
            'type'  => 'contact',
            'model' => $ctx['seller'],
            'name'  => $ctx['seller']->full_name,
            'email' => $ctx['seller']->email,
        ];
        $this->dist->send($ctx['deal'], $doc, 'seller', 'direct_attachment', $recipient, $ctx['agent']);

        $resp = $this->actingAs($ctx['agent'])
            ->get(route('corex.properties.show', $ctx['property']));

        $resp->assertOk();
        // The controller resolved the property-pillar comms for this viewer …
        $rendered = $resp->viewData('propertyComms');
        $this->assertNotNull($rendered);
        $this->assertGreaterThanOrEqual(1, $rendered->count(), 'property show resolves ≥1 distribution');
        // … and the card is on the page (Overview is the default tab).
        $resp->assertSee('Document Distributions');
    }

    // ── Prevent-or-absorb: a soft-deleted recipient contact never 500s ──────

    public function test_property_surface_survives_a_deleted_recipient_contact(): void
    {
        Mail::fake();
        $ctx = $this->scaffold();

        $doc = $this->docOnDeal($ctx, $ctx['otpType']->id, 'x.pdf');
        $recipient = [
            'type'  => 'contact',
            'model' => $ctx['seller'],
            'name'  => $ctx['seller']->full_name,
            'email' => $ctx['seller']->email,
        ];
        $this->dist->send($ctx['deal'], $doc, 'seller', 'direct_attachment', $recipient, $ctx['agent']);

        // The recipient contact is archived after the send.
        $ctx['seller']->delete();

        // The property pillar still renders its distribution — the comm carries
        // its own participant identifiers and owner, independent of the contact.
        $resp = $this->actingAs($ctx['agent'])
            ->get(route('corex.properties.show', $ctx['property']));
        $resp->assertOk();
        $this->assertGreaterThanOrEqual(1, $resp->viewData('propertyComms')->count());
    }

    // ── A viewer without comms visibility gets no rows (POPIA / scope) ───────

    public function test_property_surface_hides_rows_from_a_viewer_without_visibility(): void
    {
        Mail::fake();
        $ctx = $this->scaffold();

        $doc = $this->docOnDeal($ctx, $ctx['otpType']->id, 'y.pdf');
        $recipient = [
            'type'  => 'contact',
            'model' => $ctx['seller'],
            'name'  => $ctx['seller']->full_name,
            'email' => $ctx['seller']->email,
        ];
        $this->dist->send($ctx['deal'], $doc, 'seller', 'direct_attachment', $recipient, $ctx['agent']);

        // A DIFFERENT agent in the same agency but a different branch, with only
        // 'own' comms scope, does not own this distribution → sees nothing.
        $other = User::factory()->create([
            'agency_id' => $ctx['agencyId'], 'branch_id' => $ctx['agencyId'], 'role' => 'agent',
        ]);

        $rows = Communication::query()
            ->notPurged()
            ->whereHas('links', function ($q) use ($ctx) {
                $q->where('linkable_type', Property::class)
                  ->where('linkable_id', $ctx['property']->id);
            })
            ->visibleTo($other, 'own')
            ->get();

        $this->assertTrue($rows->isEmpty(), 'a non-owner with own-scope sees no distribution');
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function assertContainsMorph(string $type, int $id, Communication $comm, string $msg): void
    {
        $this->assertTrue(
            $comm->links()->where('linkable_type', $type)->where('linkable_id', $id)->exists(),
            $msg
        );
    }

    private function docOnDeal(array $ctx, int $typeId, string $name): Document
    {
        Storage::disk('local')->put($p = "deals/{$ctx['deal']->id}/{$name}", '%PDF-1.4 test');
        return app(DealDocumentService::class)->createDealDocument($ctx['deal'], [
            'original_name'    => $name,
            'storage_path'     => $p,
            'disk'             => 'local',
            'mime_type'        => 'application/pdf',
            'size'             => 13,
            'document_type_id' => $typeId,
            'source_type'      => 'deal_upload',
        ], $ctx['agent']);
    }

    private function scaffold(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC ' . Str::random(6), 'slug' => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '8 Marine Drive, Shelly Beach',
            'address' => '8 Marine Drive, Shelly Beach', 'suburb' => 'Shelly Beach', 'erf_number' => '1234',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
        ]));

        $seller = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'Annelise', 'last_name' => 'van der Merwe',
            'email' => 'seller' . Str::random(4) . '@example.co.za',
            'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
        ]));

        $otpType = DocumentType::create(['slug' => 'otp-' . Str::random(5), 'label' => 'Offer to Purchase', 'is_active' => true]);

        $template = $this->makeTemplate($agencyId, $agent->id);

        $deal = $this->engine->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id,
            'listing_agent_id' => $agent->id, 'pipeline_template_id' => $template->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $agencyId, 'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
            'contacts' => [['contact_id' => $seller->id, 'role' => 'seller']],
        ]);

        return compact('agencyId', 'agent', 'property', 'seller', 'otpType', 'template', 'deal');
    }

    private function makeTemplate(int $agencyId, int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'WS5 Test', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);

        DealPipelineStep::create([
            'pipeline_template_id' => $template->id, 'agency_id' => $agencyId,
            'position' => 1, 'name' => 'OTP Signed', 'completion_type' => 'date_input',
            'trigger_type' => 'on_creation', 'days_offset' => 0,
            'rag_green_days' => 14, 'rag_amber_days' => 7, 'rag_red_days' => 3,
            'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
            'requires_bm_approval' => false,
        ]);

        return $template;
    }
}
