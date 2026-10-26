<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureAdoption;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignaturePlaceGeneration;
use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\SignatureAdoptionService;
use App\Services\Docuperfect\SignaturePdfService;
use App\Services\Docuperfect\SignaturePlaceGenerationRecorder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * esign-signing-modes.md Build step 2 — the adoption event (a signer adopting their signature and initial once,
 * agreeing to the stored wording) is append-only evidence; placed marks carry how they got there.
 */
final class SignatureAdoptionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const SIG_A = 'data:image/png;base64,iVBORw0KGgoAAAA=';
    private const SIG_B = 'data:image/png;base64,iVBORw0KGgoBBBB=';
    private const INIT_A = 'data:image/png;base64,iVBORw0KGgoCCCC=';

    private function seller(string $mode): SignatureRequest
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);

        return $this->recipient($session['recipients'], 'seller', 1)->fresh();
    }

    private function http(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['REMOTE_ADDR' => '10.9.8.7', 'HTTP_USER_AGENT' => 'adopt-test']);
    }

    private function service(): SignatureAdoptionService
    {
        return app(SignatureAdoptionService::class);
    }

    public function test_the_approved_wording_is_exactly_what_johan_approved(): void
    {
        $this->assertSame(
            'By signing here you agree this is your signature and initial and that it will be applied wherever you click to sign or initial on this document.',
            $this->service()->wordingFor('adopt_once')['text']
        );
        // The "Apply to all my places" confirmation carries the other sentence Johan approved on 23 Oct, unchanged.
        $this->assertSame(
            'By signing here you agree this is your signature and initial and that it will be applied to every signature and initial place on this document that is yours, without you clicking each one.',
            config('esign-adoption.apply_all_notice')
        );
        $this->assertSame(1, $this->service()->wordingFor('adopt_once')['version']);
        // an adoption recorded before the ruling under a retired mode still resolves to the same wording
        $this->assertSame($this->service()->wordingFor('adopt_once')['text'], $this->service()->wordingFor('adopt_then_stamp')['text']);
    }

    public function test_only_an_adoption_mode_envelope_needs_or_accepts_an_adoption(): void
    {
        $each = $this->seller('sign_each');
        $this->assertFalse($this->service()->required($each));
        $this->assertTrue($this->service()->required($this->seller('adopt_then_stamp')));
        $this->assertTrue($this->service()->required($this->seller('adopt_and_fill_all')));

        $this->expectException(DomainException::class);
        $this->service()->adopt($each, self::SIG_A, self::INIT_A, $this->http());
    }

    public function test_an_adoption_stores_the_marks_the_exact_wording_its_version_and_where_from(): void
    {
        $r = $this->seller('adopt_then_stamp');
        $row = $this->service()->adopt($r, self::SIG_A, self::INIT_A, $this->http());

        $this->assertSame(1, $row->generation);
        $this->assertSame('adopt_once', $row->mode);
        $this->assertSame(self::SIG_A, $row->signature_image);
        $this->assertSame(self::INIT_A, $row->initial_image);
        $this->assertSame(1, $row->disclaimer_version);
        $this->assertSame($this->service()->wordingFor('adopt_once')['text'], $row->disclaimer_text);
        $this->assertSame('10.9.8.7', $row->ip_address);
        $this->assertSame('adopt-test', $row->user_agent);
        $this->assertNotNull($row->adopted_at);
        $this->assertNull($row->superseded_at);
        $this->assertSame($row->id, $this->service()->current($r)->id);

        $log = SignatureAuditLog::where('action', 'signature_adopted')->firstOrFail();
        $this->assertSame($r->id, (int) $log->signature_request_id);
        $this->assertSame(1, $log->metadata_json['disclaimer_version']);
        $this->assertStringContainsString('adopted their signature and initial (agreement version 1)', SignaturePdfService::auditActionDescription($log));
    }

    public function test_adopting_the_same_marks_again_changes_nothing_and_different_marks_are_a_new_generation(): void
    {
        $r = $this->seller('adopt_and_fill_all');
        $first = $this->service()->adopt($r, self::SIG_A, self::INIT_A, $this->http());

        $again = $this->service()->adopt($r, self::SIG_A, self::INIT_A, $this->http());
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, SignatureAdoption::count());

        $second = $this->service()->adopt($r, self::SIG_B, self::INIT_A, $this->http());
        $this->assertSame(2, $second->generation);
        $this->assertSame(2, SignatureAdoption::count(), 'both adoptions are on record');

        $first->refresh();
        $this->assertSame(self::SIG_A, $first->signature_image, 'the original adoption is untouched');
        $this->assertNotNull($first->superseded_at);
        $this->assertSame($second->id, (int) $first->superseded_by_id);
        $this->assertSame($second->id, $this->service()->current($r)->id);

        $this->assertSame(1, SignatureAuditLog::where('action', 'signature_readopted')->count());
        $this->assertSame(1, SignatureAuditLog::where('action', 'signature_adopted')->count());
    }

    public function test_a_bad_image_is_refused_and_nothing_is_recorded(): void
    {
        $r = $this->seller('adopt_then_stamp');

        foreach (['', 'not an image', 'data:text/html;base64,PHNjcmlwdD4=', 'data:image/png;base64,@@@'] as $bad) {
            try {
                $this->service()->adopt($r, $bad, self::INIT_A, $this->http());
                $this->fail("'{$bad}' must be refused");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, SignatureAdoption::count());
        $this->assertSame(0, SignatureAuditLog::where('action', 'signature_adopted')->count());
    }

    public function test_an_adoption_is_evidence_it_cannot_be_edited_or_deleted(): void
    {
        $row = $this->service()->adopt($this->seller('adopt_then_stamp'), self::SIG_A, self::INIT_A, $this->http());

        try {
            $row->update(['signature_image' => self::SIG_B]);
            $this->fail('editing an adoption must be refused');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        try {
            $row->delete();
            $this->fail('deleting an adoption must be refused');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->assertSame(self::SIG_A, $row->fresh()->signature_image);
    }

    public function test_a_placed_mark_records_how_it_got_there_and_a_plain_signing_reads_as_drawn(): void
    {
        $r = $this->seller('adopt_then_stamp');
        $document = $r->template->document;
        $adoption = $this->service()->adopt($r, self::SIG_A, self::INIT_A, $this->http());
        $recorder = app(SignaturePlaceGenerationRecorder::class);

        $recorder->record($document, $r, ['seller-init-0' => self::INIT_A], [], $this->http(), [
            'method' => SignaturePlaceGeneration::METHOD_STAMPED_CLICK, 'adoption_id' => $adoption->id,
        ]);
        $recorder->record($document, $r, ['owner_party-sig-0' => self::SIG_A], [], $this->http());

        $stamped = SignaturePlaceGeneration::forPlace($document->id, 'seller-init-0')->firstOrFail();
        $this->assertSame('stamped_click', $stamped->method);
        $this->assertSame($adoption->id, (int) $stamped->adoption_id);
        $this->assertNull($stamped->fill_all_event_id);

        $drawn = SignaturePlaceGeneration::forPlace($document->id, 'owner_party-sig-0')->firstOrFail();
        $this->assertSame('drawn', $drawn->method);
        $this->assertNull($drawn->adoption_id);
    }

    public function test_the_certificate_trail_words_a_click_stamp_and_a_fill_all(): void
    {
        $placed = new SignatureAuditLog(['action' => 'signature_placed', 'actor_name' => 'Pat', 'metadata_json' => ['kind' => 'initial', 'page' => 3]]);
        $fill = new SignatureAuditLog(['action' => 'fill_all_confirmed', 'actor_name' => 'Pat', 'metadata_json' => ['filled_count' => 14, 'excluded_count' => 2]]);

        $this->assertSame('Pat placed their initial by clicking on page 3', SignaturePdfService::auditActionDescription($placed));
        $this->assertSame('Pat confirmed placing their signature and initial on 14 places at once (2 left to do one by one)', SignaturePdfService::auditActionDescription($fill));
    }
}
