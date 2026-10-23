<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SectionAcceptance;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * Johan's signature-evidence rule (9 Sep 2026): a repeat section acceptance must never overwrite the earlier one.
 * SectionAcceptance::updateOrCreate used to replace initial_image / initialled_at on the same
 * (signature_request_id, section_index) row; each acceptance or rejection is now its own generation.
 */
final class SectionAcceptanceGenerationsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const IMG_A = 'data:image/png;base64,iVBORw0KGgoAAAA=';
    private const IMG_B = 'data:image/png;base64,iVBORw0KGgoBBBB=';

    private function seller(): SignatureRequest
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);

        return $this->recipient($session['recipients'], 'seller', 1);
    }

    private function accept(SignatureRequest $r, string $image)
    {
        return $this->postJson('/sign/' . $r->token . '/sections/accept', [
            'section_index' => 2,
            'section_label' => 'Clause 2',
            'initial_image' => $image,
        ]);
    }

    public function test_a_first_acceptance_is_generation_one_with_ip_and_user_agent(): void
    {
        $r = $this->seller();
        $this->accept($r, self::IMG_A)->assertOk();

        $rows = SectionAcceptance::where('signature_request_id', $r->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->generation);
        $this->assertNull($rows[0]->superseded_at);
        $this->assertNotNull($rows[0]->ip_address);
        $this->assertSame(self::IMG_A, $rows[0]->initial_image);
        $this->assertCount(1, $r->fresh()->sectionAcceptances);
    }

    public function test_a_repeat_acceptance_adds_a_generation_and_keeps_the_original(): void
    {
        $r = $this->seller();
        $this->accept($r, self::IMG_A)->assertOk();
        $firstAt = SectionAcceptance::where('signature_request_id', $r->id)->value('initialled_at');

        $this->accept($r, self::IMG_B)->assertOk();

        $all = SectionAcceptance::where('signature_request_id', $r->id)->orderBy('generation')->get();
        $this->assertCount(2, $all, 'both acceptances are on record');
        $this->assertSame(self::IMG_A, $all[0]->initial_image, 'the original initial image is untouched');
        $this->assertEquals($firstAt, $all[0]->initialled_at, 'the original time is untouched');
        $this->assertNotNull($all[0]->superseded_at);
        $this->assertSame(self::IMG_B, $all[1]->initial_image);
        $this->assertNull($all[1]->superseded_at);

        // Readers see only the latest per section.
        $current = $r->fresh()->sectionAcceptances;
        $this->assertCount(1, $current);
        $this->assertSame(self::IMG_B, $current[0]->initial_image);
        $this->assertCount(2, $r->fresh()->sectionAcceptanceHistory);
    }

    public function test_the_audit_trail_carries_each_generation(): void
    {
        $r = $this->seller();
        $this->accept($r, self::IMG_A)->assertOk();
        $this->accept($r, self::IMG_B)->assertOk();

        $gens = SignatureAuditLog::where('action', 'section_accepted')->orderBy('id')
            ->get()->map(fn ($l) => $l->metadata_json['generation'])->all();
        $this->assertSame([1, 2], $gens);
    }

    public function test_a_rejection_then_a_re_acceptance_keeps_the_whole_history(): void
    {
        $r = $this->seller();
        $this->accept($r, self::IMG_A)->assertOk();
        $this->postJson('/sign/' . $r->token . '/sections/reject', [
            'section_index' => 2, 'section_label' => 'Clause 2', 'rejection_reason' => 'Wrong amount',
        ])->assertOk();
        $this->accept($r, self::IMG_B)->assertOk();

        $all = SectionAcceptance::where('signature_request_id', $r->id)->orderBy('generation')->get();
        $this->assertSame([true, false, true], $all->pluck('accepted')->all());
        $this->assertSame('Wrong amount', $all[1]->rejection_reason);
        $this->assertSame(self::IMG_A, $all[0]->initial_image);
    }

    public function test_an_acceptance_is_evidence_it_cannot_be_edited_or_deleted(): void
    {
        $r = $this->seller();
        $this->accept($r, self::IMG_A)->assertOk();
        $row = SectionAcceptance::where('signature_request_id', $r->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $row->update(['initial_image' => self::IMG_B]);
    }
}
