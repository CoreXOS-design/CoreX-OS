<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\AmendmentAcceptance;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\EsignSettings;
use App\Models\Docuperfect\SignatureAdoption;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignaturePlaceGeneration;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\SignaturePlacementService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * esign-signing-modes.md Build step 6 — packs (one adoption, the Mode 3 count per document), proxy signing, witness
 * places, deferred signers, and the disclosure-change (AT-303) interplay.
 */
final class SigningModeStepSixTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAA=';
    private const INIT = 'data:image/png;base64,iVBORw0KGgoCCCC=';
    private const FORGED = 'data:image/png;base64,iVBORw0KGgoZZZZ=';
    private const KEY = 'disclosure_doc_1';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @return array{0: SignatureRequest, 1: array} */
    private function seller(string $mode, int $sellers = 1, bool $adopted = true): array
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: $sellers, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $r = $this->recipient($session['recipients'], 'seller', 1)->fresh();
        if ($adopted) {
            $this->adopt($r);
        }

        return [$r, $session];
    }

    private function adopt(SignatureRequest $r)
    {
        return $this->postJson('/sign/' . $r->token . '/adopt', ['signature_image' => self::SIG, 'initial_image' => self::INIT, 'agreed' => true]);
    }

    private function fillAll(SignatureRequest $r, array $keys, ?array $byDocument = null)
    {
        return $this->postJson('/sign/' . $r->token . '/place-all', array_filter([
            'place_keys' => $keys, 'one_by_one' => 2, 'confirmed' => true, 'by_document' => $byDocument,
        ], fn ($v) => $v !== null));
    }

    private function marker(SignatureRequest $r, string $party, string $type = 'signature'): string
    {
        return '<p data-marker-party="' . $party . '" data-marker-type="' . $type . '" data-recipient-identity="' . $r->attestationIdentity() . '">x</p>';
    }

    /** Replace the document with a canonical body of the given wrappers (a pack when there are two or more). */
    private function setCanonical(array $session, array $wrappers, array $templateIds = []): void
    {
        $document = $session['document'];
        $data = $document->web_template_data ?? [];
        $data['canonical_html'] = implode('', array_map(fn ($inner) => '<div class="corex-document-wrapper">' . $inner . '</div>', $wrappers));
        if ($templateIds !== []) {
            $data['template_ids'] = $templateIds;
        }
        $document->update(['web_template_data' => $data]);
    }

    /** A seller with a two-document pack: document 1 carries one signature place for them, document 2 carries two. */
    private function packSeller(string $mode): array
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $r = $this->recipient($session['recipients'], 'seller', 1)->fresh();
        $a = DocuperfectTemplate::create(['name' => 'Sole Mandate', 'render_type' => 'web', 'blade_view' => 'x.y', 'template_type' => 'cds', 'category' => 'sales', 'owner_id' => $session['creator']->id]);
        $b = DocuperfectTemplate::create(['name' => 'Disclosure Form', 'render_type' => 'web', 'blade_view' => 'x.y', 'template_type' => 'cds', 'category' => 'sales', 'owner_id' => $session['creator']->id]);
        $this->setCanonical($session, [
            $this->marker($r, 'seller'),
            $this->marker($r, 'seller') . $this->marker($r, 'seller'),
        ], [$a->id, $b->id]);
        $this->adopt($r)->assertOk();

        return [$r->fresh(), $session];
    }

    // ───────────────────────────── packs ─────────────────────────────

    public function test_a_pack_is_one_adoption_and_the_stamp_works_across_every_document(): void
    {
        [$r] = $this->packSeller('adopt_then_stamp');

        $this->adopt($r)->assertOk(); // confirming the same marks again is the same adoption
        $this->assertSame(1, SignatureAdoption::where('signature_request_id', $r->id)->count(), 'one adoption for the whole pack');

        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertOk();   // document 1
        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-2'])->assertOk();   // document 2
        $this->assertSame(2, SignaturePlaceGeneration::where('signature_request_id', $r->id)->count());
        $this->assertSame(1, SignatureAdoption::where('signature_request_id', $r->id)->count(), 'no second adoption for the second document');
    }

    public function test_the_page_hands_the_member_documents_to_the_confirmation(): void
    {
        [$r] = $this->packSeller('adopt_and_fill_all');

        $docs = app(SignaturePlacementService::class)->packDocuments($r);
        $this->assertSame([['index' => 0, 'name' => 'Sole Mandate'], ['index' => 1, 'name' => 'Disclosure Form']], $docs);

        $html = $this->asRecipient($r)->assertOk()->getContent();
        $this->assertStringContainsString('Sole Mandate', $html);
        $this->assertStringContainsString('Disclosure Form', $html);
        $this->assertStringContainsString('data-fill-all-documents', $html, 'the confirmation lists the count per document');
    }

    public function test_a_single_document_is_not_a_pack(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');

        $this->assertSame([], app(SignaturePlacementService::class)->packDocuments($r));
    }

    public function test_mode_three_pack_fill_all_records_the_count_for_each_document(): void
    {
        [$r] = $this->packSeller('adopt_and_fill_all');

        $res = $this->fillAll($r, ['seller-sig-0', 'seller-sig-1', 'seller-sig-2', 'seller-init-0', 'seller-init-1'], [
            ['index' => 0, 'signatures' => 1, 'initials' => 1],
            ['index' => 1, 'signatures' => 2, 'initials' => 1],
        ]);
        $res->assertOk()->assertJson(['ok' => true, 'count' => 5]);

        $event = SignatureAuditLog::findOrFail((int) $res->json('event_id'));
        $this->assertSame(5, $event->metadata_json['count_filled']);
        $this->assertEquals([
            ['index' => 0, 'name' => 'Sole Mandate', 'signatures' => 1, 'initials' => 1],
            ['index' => 1, 'name' => 'Disclosure Form', 'signatures' => 2, 'initials' => 1],
        ], $event->metadata_json['per_document'], 'the confirmation states each document, named by the server, not one total');
        $this->assertSame(1, SignatureAdoption::where('signature_request_id', $r->id)->count());
    }

    public function test_a_pack_fill_all_without_the_per_document_breakdown_is_refused(): void
    {
        [$r] = $this->packSeller('adopt_and_fill_all');

        $this->fillAll($r, ['seller-sig-0'])->assertStatus(422);
        $this->assertSame(0, SignaturePlaceGeneration::count());
        $this->assertSame(0, SignatureAuditLog::where('action', 'fill_all_confirmed')->count());
    }

    public function test_a_breakdown_that_does_not_add_up_is_refused(): void
    {
        [$r] = $this->packSeller('adopt_and_fill_all');

        $this->fillAll($r, ['seller-sig-0', 'seller-sig-1'], [['index' => 0, 'signatures' => 1, 'initials' => 0]])->assertStatus(422);
        $this->fillAll($r, ['seller-sig-0'], [['index' => 7, 'signatures' => 1, 'initials' => 0]])->assertStatus(422);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_a_document_cannot_claim_more_signature_places_than_it_carries_for_the_signer(): void
    {
        [$r] = $this->packSeller('adopt_and_fill_all');

        // Three in total is right, but document 1 carries only one for this signer.
        $this->fillAll($r, ['seller-sig-0', 'seller-sig-1', 'seller-sig-2'], [
            ['index' => 0, 'signatures' => 2, 'initials' => 0],
            ['index' => 1, 'signatures' => 1, 'initials' => 0],
        ])->assertStatus(409)->assertJson(['fill_all_unavailable' => true]);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    // ───────────────────────────── proxy ─────────────────────────────

    /** Two sellers; the first signs as proxy for the second. */
    private function proxyPair(string $mode = 'adopt_and_fill_all'): array
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $proxy = $this->recipient($session['recipients'], 'seller', 1);
        $covered = $this->recipient($session['recipients'], 'seller', 2);
        $proxy->update(['is_proxy' => true]);

        return [$proxy->fresh(), $covered->fresh(), $session];
    }

    public function test_a_proxy_adopts_as_proxy_and_the_audit_names_both(): void
    {
        [$proxy, $covered, $session] = $this->proxyPair();
        $this->setCanonical($session, [$this->marker($proxy, 'seller')]);

        $this->adopt($proxy)->assertOk();
        $this->postJson('/sign/' . $proxy->token . '/place-all', ['place_keys' => ['seller-sig-0'], 'one_by_one' => 0, 'confirmed' => true])->assertOk();

        $adopted = SignatureAuditLog::where('action', 'signature_adopted')->firstOrFail();
        $this->assertSame($proxy->id, (int) $adopted->signature_request_id, 'the adoption is the proxy\'s own');
        $this->assertSame('proxy', $adopted->metadata_json['signing_as']);
        $this->assertSame($proxy->signer_name, $adopted->metadata_json['proxy_name']);
        $this->assertEquals([['request_id' => $covered->id, 'name' => $covered->signer_name]], $adopted->metadata_json['signing_for'], 'and it names who they sign for');

        foreach (['fill_all_confirmed', 'signature_placed'] as $action) {
            $row = SignatureAuditLog::where('action', $action)->firstOrFail();
            $this->assertSame('proxy', $row->metadata_json['signing_as'], $action . ' carries the proxy capacity');
            $this->assertSame($covered->signer_name, $row->metadata_json['signing_for'][0]['name']);
        }

        $generation = SignaturePlaceGeneration::firstOrFail();
        $this->assertSame($proxy->id, (int) $generation->signature_request_id);
        $this->assertSame($proxy->signer_name, $generation->signer_name);
    }

    public function test_a_party_the_proxy_signs_for_never_adopts_or_places(): void
    {
        [, $covered] = $this->proxyPair();

        $this->adopt($covered)->assertStatus(409);
        $this->postJson('/sign/' . $covered->token . '/place', ['place_key' => 'seller-sig-0'])->assertStatus(409);
        $this->assertSame(0, SignatureAdoption::count(), 'a covered party has nothing to adopt');
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_an_ordinary_signer_audit_carries_no_proxy_fields(): void
    {
        [$r] = $this->seller('adopt_then_stamp');
        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertOk();

        foreach (SignatureAuditLog::whereIn('action', ['signature_adopted', 'signature_placed'])->get() as $row) {
            $this->assertArrayNotHasKey('signing_as', $row->metadata_json);
        }
    }

    // ───────────────────────────── witness ─────────────────────────────

    public function test_a_witness_place_is_never_stamped_or_filled_for_another_party(): void
    {
        [$r, $session] = $this->seller('adopt_and_fill_all');
        $this->setCanonical($session, [$this->marker($r, 'seller') . '<p data-marker-party="witness" data-marker-type="signature" data-recipient-identity="witness_1">w</p>']);

        $this->fillAll($r, ['seller-sig-0', 'witness-sig-0'])->assertStatus(422);
        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'witness-sig-0'])->assertStatus(422);
        $this->assertSame(0, SignaturePlaceGeneration::count());

        // …and the seller's own place is unaffected.
        $this->fillAll($r, ['seller-sig-0'])->assertOk();
        $this->assertSame(['seller-sig-0'], SignaturePlaceGeneration::pluck('place_key')->all());
    }

    public function test_a_witness_cell_shown_to_another_party_is_a_deliberate_capture_not_a_forgery(): void
    {
        [$r] = $this->seller('adopt_then_stamp');
        $placement = app(SignaturePlacementService::class);

        $this->assertSame([], $placement->unadoptedMarks($r, ['witness-sig-0' => self::FORGED]), 'a drawn mark on a witness cell is the deliberate capture §7 allows');
        $this->assertSame(['seller-sig-0'], $placement->unadoptedMarks($r, ['seller-sig-0' => self::FORGED]), 'their own place still must be the adopted mark');
    }

    public function test_a_witness_who_is_a_recipient_adopts_and_is_filled_only_on_their_own_places(): void
    {
        [$r, $session] = $this->seller('adopt_and_fill_all');
        $witness = app(SignatureService::class)->createSigningRequest(
            template: $session['signatureTemplate'], partyRole: 'witness', signerName: 'Wendy Witness', signerEmail: 'wendy@x.test', roleIndex: 1,
        );
        SignatureRequest::whereKey($witness->id)->update(['status' => SignatureRequest::STATUS_PENDING]);
        $witness = $witness->fresh();
        $this->setCanonical($session, [$this->marker($r, 'seller') . $this->marker($witness, 'witness')]);

        $this->adopt($witness)->assertOk();
        $this->fillAll($witness, ['witness-sig-0'])->assertOk();
        $this->fillAll($witness, ['seller-sig-0'])->assertStatus(422);

        $row = SignaturePlaceGeneration::firstOrFail();
        $this->assertSame('witness-sig-0', $row->place_key);
        $this->assertSame($witness->id, (int) $row->signature_request_id);
        $this->assertSame('stamped_auto', $row->method);
    }

    // ───────────────────────────── deferred ─────────────────────────────

    public function test_a_deferred_or_waiting_signer_cannot_adopt_or_place_until_their_turn(): void
    {
        [$r] = $this->seller('adopt_then_stamp', adopted: false);

        foreach ([SignatureRequest::STATUS_DEFERRED, SignatureRequest::STATUS_WAITING] as $status) {
            SignatureRequest::whereKey($r->id)->update(['status' => $status]);
            $this->adopt($r->fresh())->assertStatus(403);
            $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertStatus(403);
        }
        $this->assertSame(0, SignatureAdoption::count());
    }

    public function test_a_deferred_signer_adopts_on_their_own_turn_in_the_envelope_mode_not_the_agency_setting(): void
    {
        [$r, $session] = $this->seller('adopt_then_stamp', adopted: false);
        SignatureRequest::whereKey($r->id)->update(['status' => SignatureRequest::STATUS_DEFERRED]);

        // The agency changes its setting while the signer is still deferred.
        $agencyId = (int) $session['signatureTemplate']->agency_id;
        $settings = EsignSettings::forAgency($agencyId);
        $settings->signing_mode = 'sign_each';
        $settings->save();

        // Their turn comes (what resume + advance do): they are asked to adopt, in the mode the envelope was sent in.
        SignatureRequest::whereKey($r->id)->update(['status' => SignatureRequest::STATUS_PENDING]);
        $r = $r->fresh();
        $this->adopt($r)->assertOk();
        $this->assertSame('adopt_once', SignatureAdoption::firstOrFail()->mode, 'the envelope kept the mode it was sent in, not the new agency setting');
        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertOk();
        $this->assertSame('stamped_click', SignaturePlaceGeneration::firstOrFail()->method);
        $this->fillAll($r, ['seller-sig-0', 'seller-init-0'])->assertOk(); // apply-to-all is part of the same choice
    }

    // ─────────────────────── disclosure change (AT-303) ───────────────────────

    /** Two sellers; seller 1 has signed and locked the disclosure. */
    private function lockedPair(string $mode): array
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $s1 = $this->recipient($session['recipients'], 'seller', 1)->fresh();
        $s2 = $this->recipient($session['recipients'], 'seller', 2)->fresh();

        return [$s1, $s2, $session];
    }

    private function lock(array $session, SignatureRequest $s1): void
    {
        $s1->update(['status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now()]);
        $document = $session['document'];
        $data = $document->web_template_data ?? [];
        $data['disclosure_answers'] = [self::KEY => 'yes'];
        $data['disclosure_lock'] = [
            'locked' => true, 'request_id' => $s1->id, 'role_identity' => $s1->role_identity,
            'signer_name' => $s1->signer_name, 'locked_at' => now()->toIso8601String(), 'answers' => [self::KEY => 'yes'],
        ];
        $document->update(['web_template_data' => $data]);
    }

    private function propose(SignatureRequest $r, array $over = [])
    {
        return $this->postJson('/sign/' . $r->token . '/disclosure/' . self::KEY . '/amend', $over + [
            'new_value' => 'no', 'statement' => 'I am aware of defects in the roof',
        ]);
    }

    public function test_the_proposer_initials_a_disclosure_change_with_a_click_using_the_adopted_initial(): void
    {
        [$s1, $s2, $session] = $this->lockedPair('adopt_then_stamp');
        $this->lock($session, $s1);
        $this->adopt($s2)->assertOk();

        $this->propose($s2, ['use_adopted' => true, 'initial_image' => self::FORGED])->assertOk();

        $amendment = DocumentAmendment::firstOrFail();
        $accepted = AmendmentAcceptance::where('amendment_id', $amendment->id)->where('signature_request_id', $s2->id)->firstOrFail();
        $this->assertSame(self::INIT, $accepted->initial_image, 'the initial is the server-held adoption, whatever the request carried');

        $generation = SignaturePlaceGeneration::where('signature_request_id', $s2->id)->firstOrFail();
        $this->assertSame('r' . $s2->id . '-init-disclosure-' . self::KEY, $generation->place_key);
        $this->assertSame('stamped_click', $generation->method);
        $audit = SignatureAuditLog::where('action', 'signature_placed')->firstOrFail();
        $this->assertSame('disclosure_change_initial', $audit->metadata_json['deliberate_act']);
    }

    public function test_the_adopted_initial_cannot_be_used_outside_an_adoption_mode_or_before_adopting(): void
    {
        [$s1, $s2, $session] = $this->lockedPair('sign_each');
        $this->lock($session, $s1);
        $this->propose($s2, ['use_adopted' => true])->assertStatus(409);
        $this->assertSame(0, DocumentAmendment::count());

        [$t1, $t2, $session2] = $this->lockedPair('adopt_then_stamp');
        $this->lock($session2, $t1);
        $this->propose($t2, ['use_adopted' => true])->assertStatus(409); // has not adopted
        $this->assertSame(0, DocumentAmendment::count());
    }

    public function test_a_disclosure_change_after_adoption_reopens_only_the_affected_place(): void
    {
        [$s1, $s2, $session] = $this->lockedPair('adopt_and_fill_all');
        $this->setCanonical($session, [$this->marker($s1, 'seller') . $this->marker($s2, 'seller')]);
        $this->adopt($s1)->assertOk();
        $this->fillAll($s1, ['seller-sig-0', 'seller-init-0'])->assertOk();
        $this->lock($session, $s1->fresh());
        $this->adopt($s2)->assertOk();

        $this->propose($s2, ['use_adopted' => true])->assertOk();
        $amendment = DocumentAmendment::firstOrFail();

        // The earlier signer is asked back for ONE thing: the counter-initial on the change.
        $s1 = $s1->fresh();
        $this->assertSame(SignatureRequest::STATUS_PENDING, $s1->status);
        $counter = AmendmentAcceptance::where('amendment_id', $amendment->id)->where('signature_request_id', $s1->id)->firstOrFail();
        $this->assertFalse((bool) $counter->accepted);

        // Everything they had already stamped stands: same generations, none superseded, still the one adoption.
        $mine = SignaturePlaceGeneration::where('signature_request_id', $s1->id)->get();
        $this->assertCount(2, $mine);
        $this->assertSame([null, null], $mine->map(fn ($g) => $g->superseded_at)->all());
        $this->assertSame(['stamped_auto', 'stamped_auto'], $mine->pluck('method')->all());
        $this->assertSame(1, SignatureAdoption::where('signature_request_id', $s1->id)->count(), 'no re-adoption');

        // Fill-all again writes nothing new and never touches the counter-initial.
        $res = $this->fillAll($s1, ['seller-sig-0', 'seller-init-0'])->assertOk();
        $this->assertSame(['seller-init-0', 'seller-sig-0'], $res->json('already'));
        $this->assertCount(2, SignaturePlaceGeneration::where('signature_request_id', $s1->id)->get());
        $this->assertFalse((bool) $counter->fresh()->accepted, 'the counter-initial stays theirs to give, deliberately');

        // They give it themselves (a fresh capture on the amendment screen).
        $this->postJson('/sign/' . $s1->token . '/amendment/' . $amendment->id . '/accept', ['initial_image' => self::INIT])->assertOk();
        $this->assertTrue((bool) $counter->fresh()->accepted);
    }
}
