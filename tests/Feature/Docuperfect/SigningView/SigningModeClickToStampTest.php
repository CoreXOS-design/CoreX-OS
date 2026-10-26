<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignaturePlaceGeneration;
use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * esign-signing-modes.md Build step 4 — Mode 2 (adopt, then click to stamp): a click on a place stamps the signer's
 * adopted mark there, one append-only record per click with the server's time; a client can never stamp anything but
 * the adopted mark; removing a place keeps the original on record; Mode 1 is untouched.
 */
final class SigningModeClickToStampTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAA=';
    private const INIT = 'data:image/png;base64,iVBORw0KGgoCCCC=';
    private const FORGED = 'data:image/png;base64,iVBORw0KGgoZZZZ=';

    /** @return array{0: SignatureRequest, 1: array} */
    private function seller(string $mode, bool $adopted = true): array
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);
        $r = $this->recipient($session['recipients'], 'seller', 1)->fresh();
        if ($adopted && $mode !== 'sign_each') {
            $this->postJson('/sign/' . $r->token . '/adopt', ['signature_image' => self::SIG, 'initial_image' => self::INIT, 'agreed' => true])->assertOk();
        }

        return [$r, $session];
    }

    private function place(SignatureRequest $r, string $key, array $extra = [])
    {
        return $this->postJson('/sign/' . $r->token . '/place', ['place_key' => $key] + $extra);
    }

    public function test_a_click_stamps_the_adopted_signature_and_records_it_with_the_server_time(): void
    {
        [$r, $session] = $this->seller('adopt_then_stamp');

        // Even if a client sends its own image alongside, only the adopted mark is ever used.
        $this->place($r, 'seller-sig-0', ['image' => self::FORGED])->assertOk()->assertJson(['ok' => true, 'generation' => 1]);

        $row = SignaturePlaceGeneration::firstOrFail();
        $this->assertSame(self::SIG, $row->image);
        $this->assertSame('stamped_click', $row->method);
        $this->assertSame('signature', $row->kind);
        $this->assertSame('seller-sig-0', $row->place_key);
        $this->assertSame($r->id, (int) $row->signature_request_id);
        $this->assertSame((int) $session['document']->id, (int) $row->document_id);
        $this->assertNotNull($row->adoption_id);
        $this->assertNotNull($row->signed_at);
        $this->assertNotEmpty($row->ip_address);

        $audit = SignatureAuditLog::where('signature_request_id', $r->id)->where('action', 'signature_placed')->firstOrFail();
        $this->assertSame('seller-sig-0', $audit->metadata_json['place_key']);
        $this->assertSame('stamped_click', $audit->metadata_json['method']);
        $this->assertSame((int) $row->adoption_id, (int) $audit->metadata_json['adoption_id']);
    }

    public function test_an_initial_place_gets_the_adopted_initial_not_the_signature(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $this->place($r, 'seller-init-2')->assertOk();

        $row = SignaturePlaceGeneration::firstOrFail();
        $this->assertSame(self::INIT, $row->image);
        $this->assertSame('initial', $row->kind);
    }

    public function test_clicking_the_same_place_twice_records_it_once(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $this->place($r, 'seller-sig-0')->assertOk();
        $this->place($r, 'seller-sig-0')->assertOk()->assertJson(['already_placed' => true]);

        $this->assertSame(1, SignaturePlaceGeneration::count());
        $this->assertSame(1, SignatureAuditLog::where('action', 'signature_placed')->count());
    }

    public function test_nothing_can_be_stamped_before_adopting(): void
    {
        [$r] = $this->seller('adopt_then_stamp', adopted: false);

        // Johan, 26 Oct 2026: refused with a signal the page answers by opening the adopt popup (never a silent failure).
        $this->place($r, 'seller-sig-0')->assertStatus(422)->assertJsonPath('adoption_required', true);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_a_default_mode_envelope_has_no_stamping(): void
    {
        [$r] = $this->seller('sign_each');

        $this->place($r, 'seller-sig-0')->assertStatus(409);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_agents_are_not_offered_stamping(): void
    {
        [, $session] = $this->seller('adopt_then_stamp');
        $agent = $this->recipient($session['recipients'], 'agent', 1)->fresh();

        $this->place($agent, 'agent-sig-0')->assertStatus(409);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_a_place_that_belongs_to_another_party_is_refused(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $this->place($r, 'buyer-sig-0')->assertStatus(422);
        $this->place($r, 'agent-init-1')->assertStatus(422);
        $this->place($r, 'not-a-place')->assertStatus(422);
        $this->place($r, 'seller-sig-0; drop table')->assertStatus(422);
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_taking_a_place_back_keeps_the_original_and_clicking_again_writes_generation_two(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $this->place($r, 'seller-sig-0')->assertOk();
        $this->postJson('/sign/' . $r->token . '/place/remove', ['place_key' => 'seller-sig-0'])->assertOk()->assertJson(['removed' => true]);

        $first = SignaturePlaceGeneration::where('generation', 1)->firstOrFail();
        $this->assertNotNull($first->superseded_at, 'the removed mark is stamped superseded, not deleted');
        $this->assertSame(1, SignatureAuditLog::where('action', 'signature_place_removed')->count());

        $this->place($r, 'seller-sig-0')->assertOk()->assertJson(['generation' => 2]);
        $this->assertSame(2, SignaturePlaceGeneration::count());
        $this->assertNull(SignaturePlaceGeneration::where('generation', 2)->firstOrFail()->superseded_at);
    }

    public function test_only_your_own_stamped_mark_can_be_taken_back(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $this->postJson('/sign/' . $r->token . '/place/remove', ['place_key' => 'seller-sig-0'])->assertOk()->assertJson(['removed' => false]);
        $this->assertSame(0, SignatureAuditLog::where('action', 'signature_place_removed')->count());
    }

    public function test_completing_with_an_image_that_was_never_adopted_is_refused(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $res = $this->postJson('/sign/' . $r->token . '/complete-web', [
            'consented' => true,
            'signatures' => ['seller-sig-0' => self::FORGED],
            'initials' => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('adopted', (string) $res->json('error'));
        $this->assertSame(0, SignaturePlaceGeneration::count());
    }

    public function test_an_adopted_signature_cannot_be_posted_into_an_initial_place(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $this->postJson('/sign/' . $r->token . '/complete-web', [
            'consented' => true,
            'signatures' => [],
            'initials' => ['seller-init-0' => self::SIG],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ])->assertStatus(422);
    }

    public function test_completing_keeps_the_click_records_and_adds_one_for_a_click_the_server_never_heard(): void
    {
        [$r] = $this->seller('adopt_then_stamp');
        $this->place($r, 'seller-sig-0')->assertOk();

        $res = $this->postJson('/sign/' . $r->token . '/complete-web', [
            'consented' => true,
            'signatures' => ['seller-sig-0' => self::SIG],
            // seller-init-0 was clicked on a bad connection: the server hears about it here.
            'initials' => ['seller-init-0' => self::INIT],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);
        $this->assertNotSame(422, $res->getStatusCode(), (string) $res->getContent());

        $this->assertSame(1, SignaturePlaceGeneration::where('place_key', 'seller-sig-0')->count(), 'the click record is not duplicated');
        $late = SignaturePlaceGeneration::where('place_key', 'seller-init-0')->firstOrFail();
        $this->assertSame('stamped_click', $late->method);
        $this->assertNotNull($late->adoption_id);
        $this->assertTrue((bool) SignatureAuditLog::where('action', 'signature_placed')
            ->get()->contains(fn ($a) => ($a->metadata_json['place_key'] ?? null) === 'seller-init-0' && ! empty($a->metadata_json['reconciled_at_completion'])));
    }

    public function test_a_mode_one_envelope_is_unchanged_by_all_of_this(): void
    {
        [$r] = $this->seller('sign_each');

        $res = $this->postJson('/sign/' . $r->token . '/complete-web', [
            'consented' => true,
            'signatures' => ['seller-sig-0' => self::FORGED],
            'initials' => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);

        $this->assertNotSame(422, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSame('drawn', SignaturePlaceGeneration::firstOrFail()->method, 'a Mode 1 mark is a drawn mark');
    }

    public function test_the_page_carries_the_stamping_script_and_renders_on_a_phone(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $html = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'])
            ->get('/sign/' . $r->token)->assertOk()->getContent();

        $this->assertStringContainsString('_stampClick', $html);
        $this->assertStringContainsString('/place/remove', $html);
        $this->assertStringContainsString('min-height: 44px', $html, 'stamp places have a phone-sized tap target');
        $this->assertStringContainsString('name="viewport"', $html);
    }
}
