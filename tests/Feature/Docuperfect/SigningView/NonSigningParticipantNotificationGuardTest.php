<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Elize's rule via Johan, 2026-08-24: every party always displays with full
 * details; everyone signs UNLESS deceased (never signs) or collapsed by a
 * proxy elsewhere in their group (only the proxy signs). ONE predicate
 * (SignatureRequest::isSigningParticipant()) checked at ONE choke point
 * (SignatureService::sendSigningRequest()) guarantees a non-participant is
 * never invited — proven here for BOTH reasons a party doesn't sign, since
 * they reach the same guard through different logic paths and Johan
 * specifically asked both be proven, not just one.
 */
final class NonSigningParticipantNotificationGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test Agency', 'slug' => 'test-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Test Branch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeSignatureTemplate(): SignatureTemplate
    {
        $creator = \App\Models\User::factory()->create(['agency_id' => $this->agencyId]);

        $docTemplate = DocuperfectTemplate::create([
            'name' => 'Test Template', 'render_type' => 'web', 'blade_view' => 'test-fixtures.dummy',
            'template_type' => 'cds', 'category' => 'sales', 'owner_id' => $creator->id,
        ]);
        $document = Document::create([
            'name' => 'Test Doc', 'document_type' => 'agreement', 'owner_id' => $creator->id,
            'agency_id' => $this->agencyId, 'template_id' => $docTemplate->id,
            'web_template_data' => ['merged_html' => '<div></div>'],
        ]);

        return SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $creator->id,
        ]);
    }

    /**
     * Five sellers, one flagged proxy. Same shape as
     * test_entity_recipient_with_proxy_expands_to_single_signer, generalised
     * to any group of recipients, not only ones arriving via the
     * Contact-representative mechanism.
     */
    public function test_five_recipients_one_proxy_only_one_notified(): void
    {
        $signatureTemplate = $this->makeSignatureTemplate();
        $service = app(SignatureService::class);

        $requests = collect();
        for ($i = 1; $i <= 5; $i++) {
            $requests->push($service->createSigningRequest(
                template: $signatureTemplate, partyRole: 'seller',
                signerName: "Director {$i}", signerEmail: "director{$i}@x.test", roleIndex: $i,
            ));
        }

        // The proxy flag is set on the recipient screen for THIS document — director 3.
        $requests[2]->update(['is_proxy' => true]);
        $requests->each(fn (SignatureRequest $r) => $r->refresh());

        foreach ($requests as $r) {
            $service->sendSigningRequest($r);
            $r->refresh();
        }

        $eligible = $requests->filter(fn (SignatureRequest $r) => $r->status === SignatureRequest::STATUS_PENDING);
        $notRequired = $requests->filter(fn (SignatureRequest $r) => $r->status === SignatureRequest::STATUS_NOT_REQUIRED);

        $this->assertCount(1, $eligible, 'Exactly one recipient should be eligible for notification.');
        $this->assertSame($requests[2]->id, $eligible->first()->id, 'The proxy is the one who signs.');
        $this->assertCount(4, $notRequired, 'The other four must be marked not_required, never invited.');

        foreach ($notRequired as $r) {
            $this->assertSame(SignatureRequest::NON_SIGNING_REASON_PROXY_COLLAPSED, $r->nonSigningReason());
            $this->assertNull($r->sent_at, 'A non-participant must never be marked sent.');
        }

        $this->assertSame(
            4,
            SignatureAuditLog::where('signature_template_id', $signatureTemplate->id)
                ->where('action', 'send_skipped_not_signing_participant')
                ->count(),
            'Every skipped send must be audited, not silently dropped.'
        );
    }

    /**
     * Deceased is a DIFFERENT reason than proxy collapse — Johan explicitly
     * asked both be proven through the same predicate/guard, not just one.
     */
    public function test_deceased_recipient_never_notified(): void
    {
        $signatureTemplate = $this->makeSignatureTemplate();
        $service = app(SignatureService::class);

        $piet = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'Piet', signerEmail: 'piet@x.test', roleIndex: 1,
        );
        $sannie = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'Sannie', signerEmail: 'sannie@x.test', roleIndex: 2,
        );
        $koos = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'Koos (Executor for Piet)', signerEmail: 'koos@x.test', roleIndex: 3,
        );

        // Marking Piet deceased on the recipient screen — displayed, never signs.
        // No proxy involved here at all — a genuinely different reason.
        $piet->update(['is_deceased' => true]);
        $piet->refresh();

        $service->sendSigningRequest($piet);
        $service->sendSigningRequest($sannie);
        $service->sendSigningRequest($koos);
        $piet->refresh();
        $sannie->refresh();
        $koos->refresh();

        $this->assertSame(SignatureRequest::STATUS_NOT_REQUIRED, $piet->status);
        $this->assertSame(SignatureRequest::NON_SIGNING_REASON_DECEASED, $piet->nonSigningReason());
        $this->assertNull($piet->sent_at, 'The deceased party must never be marked sent.');

        // Sannie and Koos are ordinary signing participants — untouched by Piet's flag.
        $this->assertSame(SignatureRequest::STATUS_PENDING, $sannie->status);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $koos->status);
        $this->assertNotNull($sannie->sent_at);
        $this->assertNotNull($koos->sent_at);
    }
}
