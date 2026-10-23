<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureAdoption;
use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\SignatureAdoptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * esign-signing-modes.md Build step 3 — the adoption step on the recipient signing page: shown only for an envelope
 * sent in an adoption mode, recorded by POST /sign/{token}/adopt, and required before the document can be completed.
 * An envelope in the default mode (sign every place) is exactly as it was.
 */
final class SigningAdoptionStepTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAA=';
    private const INIT = 'data:image/png;base64,iVBORw0KGgoCCCC=';

    /** @return array{0: SignatureRequest, 1: array} */
    private function seller(string $mode): array
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $session['signatureTemplate']->update(['signing_mode' => $mode]);

        return [$this->recipient($session['recipients'], 'seller', 1)->fresh(), $session];
    }

    private function adopt(SignatureRequest $r, array $over = [])
    {
        return $this->postJson('/sign/' . $r->token . '/adopt', $over + [
            'signature_image' => self::SIG, 'initial_image' => self::INIT, 'agreed' => true,
        ]);
    }

    public function test_the_default_mode_page_has_no_adoption_step(): void
    {
        [$r] = $this->seller('sign_each');

        $html = $this->asRecipient($r)->assertOk()->getContent();
        $this->assertStringNotContainsString('Adopt your signature and initial', $html);
        $this->assertStringContainsString('"required":false', str_replace(' ', '', $html));
    }

    public function test_an_adoption_mode_page_shows_the_step_with_the_exact_approved_wording(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $html = $this->asRecipient($r)->assertOk()->getContent();
        $this->assertStringContainsString('Adopt your signature and initial', $html);
        $this->assertStringContainsString('"required":true', str_replace(' ', '', $html));
        $this->assertStringContainsString('applied wherever you click to sign or initial on this document.', $html);
    }

    public function test_the_fill_all_mode_shows_its_own_wording(): void
    {
        [$r] = $this->seller('adopt_and_fill_all');

        $html = $this->asRecipient($r)->assertOk()->getContent();
        $this->assertStringContainsString('without you clicking each one.', $html);
    }

    public function test_adopting_records_it_and_a_reload_remembers_it(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $this->adopt($r)->assertOk()->assertJson(['ok' => true, 'adoption' => ['generation' => 1, 'disclaimer_version' => 1]]);
        $row = SignatureAdoption::firstOrFail();
        $this->assertSame($r->id, (int) $row->signature_request_id);
        $this->assertSame(self::SIG, $row->signature_image);

        $html = str_replace(' ', '', $this->asRecipient($r)->assertOk()->getContent());
        $this->assertStringContainsString('"adopted":true', $html);
    }

    public function test_the_agreement_must_be_ticked_and_bad_images_are_refused(): void
    {
        [$r] = $this->seller('adopt_then_stamp');

        $this->adopt($r, ['agreed' => false])->assertStatus(422);
        $this->adopt($r, ['signature_image' => 'nope'])->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(0, SignatureAdoption::count());
    }

    public function test_a_default_mode_envelope_cannot_be_adopted_on(): void
    {
        [$r] = $this->seller('sign_each');

        $this->adopt($r)->assertStatus(409);
        $this->assertSame(0, SignatureAdoption::count());
    }

    public function test_the_document_cannot_be_completed_before_adopting_and_can_after(): void
    {
        [$r] = $this->seller('adopt_then_stamp');
        $payload = [
            'consented' => true,
            'signatures' => ['owner_party-sig-0' => self::SIG, 'seller-init-0' => self::INIT],
            'initials' => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ];

        $blocked = $this->postJson('/sign/' . $r->token . '/complete-web', $payload);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('adopt your signature', (string) $blocked->json('error'));

        $this->adopt($r)->assertOk();
        $after = $this->postJson('/sign/' . $r->token . '/complete-web', $payload);
        $this->assertNotSame(422, $after->getStatusCode(), (string) $after->getContent());
    }

    public function test_a_default_mode_envelope_completes_exactly_as_before(): void
    {
        [$r] = $this->seller('sign_each');

        $res = $this->postJson('/sign/' . $r->token . '/complete-web', [
            'consented' => true,
            'signatures' => ['owner_party-sig-0' => self::SIG],
            'initials' => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);
        $this->assertNotSame(422, $res->getStatusCode(), (string) $res->getContent());
    }

    public function test_agents_and_supervisors_never_need_to_adopt(): void
    {
        [, $session] = $this->seller('adopt_and_fill_all');
        $agent = $this->recipient($session['recipients'], 'agent', 1);
        $service = app(SignatureAdoptionService::class);

        $this->assertFalse($service->required($agent), 'the agent keeps saved signature + apply-to-all');
        $this->assertTrue($service->required($this->recipient($session['recipients'], 'seller', 1)));
    }
}
