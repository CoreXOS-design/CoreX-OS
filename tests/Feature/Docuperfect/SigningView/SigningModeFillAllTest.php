<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\ConditionInitial;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignaturePlaceGeneration;
use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * esign-signing-modes.md Build step 5 — Mode 3 (adopt and fill all): one confirmation stamps every signature and initial
 * place that is the signer's, each with its own record pointing at that one confirmation. Other parties' places, and the
 * places the signer must act on one by one, are never touched.
 */
final class SigningModeFillAllTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAA=';
    private const INIT = 'data:image/png;base64,iVBORw0KGgoCCCC=';
    private const FORGED = 'data:image/png;base64,iVBORw0KGgoZZZZ=';

    /** @return array{0: SignatureRequest, 1: array} */
    private function seller(string $mode, int $sellers = 1, bool $adopted = true): array
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: $sellers, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $r = $this->recipient($session['recipients'], 'seller', 1)->fresh();
        if ($adopted && $mode !== 'sign_each') {
            $this->postJson('/sign/' . $r->token . '/adopt', ['signature_image' => self::SIG, 'initial_image' => self::INIT, 'agreed' => true])->assertOk();
        }

        return [$r, $session];
    }

    private function fillAll(SignatureRequest $r, array $keys, array $extra = [])
    {
        return $this->postJson('/sign/' . $r->token . '/place-all', $extra + ['place_keys' => $keys, 'one_by_one' => 3, 'confirmed' => true]);
    }

    public function test_one_confirmation_fills_every_listed_place_and_each_has_its_own_record(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');

        $res = $this->fillAll($r, ['seller-sig-0', 'seller-init-0', 'seller-init-1']);
        $res->assertOk()->assertJson(['ok' => true, 'count' => 3]);
        $eventId = (int) $res->json('event_id');

        $rows = SignaturePlaceGeneration::orderBy('place_key')->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('stamped_auto', $row->method);
            $this->assertSame($eventId, (int) $row->fill_all_event_id, 'every auto mark points at the one confirmation');
            $this->assertSame($r->id, (int) $row->signature_request_id);
            $this->assertNotNull($row->adoption_id);
            $this->assertNotNull($row->signed_at);
        }
        $this->assertSame(self::SIG, $rows->firstWhere('place_key', 'seller-sig-0')->image);
        $this->assertSame(self::INIT, $rows->firstWhere('place_key', 'seller-init-1')->image);

        $event = SignatureAuditLog::findOrFail($eventId);
        $this->assertSame('fill_all_confirmed', $event->action);
        $this->assertSame(3, $event->metadata_json['count_filled']);
        $this->assertSame(1, $event->metadata_json['count_signatures']);
        $this->assertSame(2, $event->metadata_json['count_initials']);
        $this->assertSame(3, $event->metadata_json['left_for_one_by_one']);
        $this->assertNotEmpty($event->metadata_json['place_list_hash']);

        $this->assertSame(1, SignatureAuditLog::where('action', 'fill_all_confirmed')->count());
        $placed = SignatureAuditLog::where('action', 'signature_placed')->get();
        $this->assertCount(3, $placed, 'one audit row per place');
        $this->assertSame(['stamped_auto'], $placed->pluck('metadata_json.method')->unique()->values()->all());
    }

    public function test_the_stamped_mark_is_the_adopted_one_whatever_the_request_carries(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');

        $this->fillAll($r, ['seller-sig-0'], ['images' => ['seller-sig-0' => self::FORGED], 'image' => self::FORGED])->assertOk();

        $this->assertSame(self::SIG, SignaturePlaceGeneration::firstOrFail()->image);
    }

    public function test_another_partys_places_are_never_filled_and_nothing_is_written_when_one_key_is_foreign(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');

        $this->fillAll($r, ['seller-sig-0', 'buyer-sig-0'])->assertStatus(422);
        $this->fillAll($r, ['seller-init-0', 'agent-init-1'])->assertStatus(422);

        $this->assertSame(0, SignaturePlaceGeneration::count());
        $this->assertSame(0, SignatureAuditLog::where('action', 'fill_all_confirmed')->count());
    }

    public function test_a_seller_cannot_list_more_signature_places_than_the_document_carries_for_them(): void
    {
        [$first] = $this->seller('adopt_and_fill_all', sellers: 2);

        // With two sellers on the document the first owns only their own signature place; asking for seven is more than they have.
        $res = $this->fillAll($first, ['seller-sig-0', 'seller-sig-1', 'seller-sig-2', 'seller-sig-3', 'seller-sig-4', 'seller-sig-5', 'seller-sig-6']);

        $res->assertStatus(409)->assertJson(['fill_all_unavailable' => true]);
        $this->assertSame(0, SignaturePlaceGeneration::count());
        $this->assertSame(0, SignatureAuditLog::where('action', 'fill_all_confirmed')->count(), 'a refused request leaves no confirmation behind');
    }

    public function test_the_server_does_not_trust_a_client_that_lists_more_signature_places_than_the_document_has(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');
        $keys = array_map(fn ($i) => "seller-sig-{$i}", range(0, 60));

        $this->fillAll($r, $keys)->assertStatus(409)->assertJson(['ok' => false, 'fill_all_unavailable' => true]);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_places_that_need_the_signer_one_by_one_are_untouched(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');

        $this->fillAll($r, ['seller-sig-0', 'seller-init-0'])->assertOk();

        $this->assertSame(0, ConditionInitial::count(), 'condition initials are never filled automatically');
        $this->assertSame(0, SignatureAuditLog::whereIn('action', ['condition_initial_added', 'amendment_initialed', 'disclosure_answered'])->count());
        // …and they still stop the document being completed: nothing about fill-all lifts a gate.
        $this->assertSame(2, SignaturePlaceGeneration::count());
    }

    public function test_only_mode_three_has_fill_all(): void
    {
        [$two] = $this->seller('adopt_then_stamp');
        $this->fillAll($two, ['seller-sig-0'])->assertStatus(409);

        [$one] = $this->seller('sign_each');
        $this->fillAll($one, ['seller-sig-0'])->assertStatus(409);

        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_nothing_is_filled_before_adopting_or_without_ticking_the_confirmation(): void
    {
        [$notAdopted] = $this->seller('adopt_and_fill_all', adopted: false);
        $this->fillAll($notAdopted, ['seller-sig-0'])->assertStatus(409);

        [$r] = $this->seller('adopt_and_fill_all');
        $this->fillAll($r, ['seller-sig-0'], ['confirmed' => false])->assertStatus(422);
        $this->fillAll($r, [])->assertStatus(422);

        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_agents_never_use_fill_all(): void
    {
        [, $session] = $this->seller('adopt_and_fill_all');
        $agent = $this->recipient($session['recipients'], 'agent', 1)->fresh();

        $this->fillAll($agent, ['agent-sig-0'])->assertStatus(409);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_a_place_filled_automatically_can_still_be_taken_back_and_redone(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');
        $this->fillAll($r, ['seller-sig-0'])->assertOk();

        $this->postJson('/sign/' . $r->token . '/place/remove', ['place_key' => 'seller-sig-0'])->assertOk()->assertJson(['removed' => true]);
        $this->postJson('/sign/' . $r->token . '/place', ['place_key' => 'seller-sig-0'])->assertOk()->assertJson(['generation' => 2]);

        $this->assertSame(2, SignaturePlaceGeneration::count());
        $this->assertSame('stamped_auto', SignaturePlaceGeneration::where('generation', 1)->firstOrFail()->method, 'the original keeps how it got there');
        $this->assertSame('stamped_click', SignaturePlaceGeneration::where('generation', 2)->firstOrFail()->method);
    }

    public function test_completing_after_a_fill_all_adds_no_duplicate_records(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');
        $this->fillAll($r, ['seller-sig-0', 'seller-init-0'])->assertOk();

        $res = $this->postJson('/sign/' . $r->token . '/complete-web', [
            'consented' => true,
            'signatures' => ['seller-sig-0' => self::SIG],
            'initials' => ['seller-init-0' => self::INIT],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);

        $this->assertNotSame(422, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSame(2, SignaturePlaceGeneration::count());
        $this->assertSame(['stamped_auto'], SignaturePlaceGeneration::pluck('method')->unique()->values()->all());
    }

    public function test_the_page_offers_the_confirmation_only_in_mode_three_and_it_works_on_a_phone(): void
    {
        $phone = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36'];

        [$three] = $this->seller('adopt_and_fill_all');
        $html = $this->withHeaders($phone)->get('/sign/' . $three->token)->assertOk()->getContent();
        $this->assertStringContainsString('Apply to all my places', $html);
        $this->assertStringContainsString('confirmFillAll()', $html);
        $this->assertStringContainsString('one by one afterwards', $html);
        $this->assertStringContainsString('name="viewport"', $html);

        [$two] = $this->seller('adopt_then_stamp');
        $this->assertStringNotContainsString('Apply to all my places', $this->get('/sign/' . $two->token)->assertOk()->getContent());

        [$one] = $this->seller('sign_each');
        $this->assertStringNotContainsString('Apply to all my places', $this->get('/sign/' . $one->token)->assertOk()->getContent());
    }
}
