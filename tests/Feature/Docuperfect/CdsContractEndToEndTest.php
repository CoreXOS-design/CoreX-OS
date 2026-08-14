<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\RoleBlockExpansionService;
use App\Services\Docuperfect\RoleBlockNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-0 verdict, re-run as a regression test.
 *
 * The walk-test verdict was RED on three legs, on the REAL HFC EATS:
 *   1. the importer produces no role-anchored field names,
 *   2. so the normalizer stamped 0 role blocks,
 *   3. so the renderer took the LEGACY CLUSTERING path (the knife-edge log line fired).
 *
 * Leg 1 stays true and is CORRECT — `contact.first_name` + `data-contact-type` is the right
 * shape for the CDS/pillar system. The fix teaches the normalizer to read the role from where
 * the importer actually writes it. This test asserts legs 2 and 3 are now GREEN, end to end,
 * on the naming the importer really emits.
 *
 * THE KNIFE EDGE: zero occurrences of
 *   "RoleBlockExpansionService: rendering unnormalised template via legacy clustering"
 * A zero is only meaningful if that line CAN fire, so the negative control asserts it does.
 */
final class CdsContractEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_LINE = 'rendering unnormalised template via legacy clustering';

    /** The tagged_html shape DocumentTemplateGenerator really writes for a two-party mandate. */
    private function generatorShapedHtml(): string
    {
        return <<<'HTML'
<div class="corex-document-wrapper">
  <p class="corex-section-heading"><strong>EXCLUSIVE AUTHORITY TO SELL</strong></p>
  <p>Seller: <span class="field" data-field="contact.first_name+last_name" data-contact-type="Seller" data-label="Full Name">x</span></p>
  <p>ID No: <span class="field" data-field="contact.id_number" data-contact-type="Seller" data-label="ID Number">x</span></p>
  <p>Domicilium: <span class="field" data-field="contact.address" data-contact-type="Seller" data-label="Address">x</span></p>
  <p>Email: <span class="field" data-field="contact.email" data-contact-type="Seller" data-label="Email">x</span></p>
  <p>Purchase price: <span class="field" data-field="manual.field_9" data-label="Price">x</span></p>
</div>
HTML;
    }

    /** @return Collection<int, SignatureRequest> two sellers — the multi-party crux */
    private function twoSellers(): Collection
    {
        return new Collection(
            collect([[1, 'Thandeka Mkhize'], [2, 'Sipho Ndlovu']])->map(function ($p) {
                $r = new SignatureRequest();
                $r->party_role = 'seller';
                $r->role_index = $p[0];
                $r->signer_name = $p[1];
                $r->signer_email = 'signer' . $p[0] . '@example.co.za';
                $r->role_identity = 'seller_' . $p[0];
                return $r;
            })->all()
        );
    }

    private function makeTemplate(string $taggedHtml): Template
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Elize van Wyk', 'email' => 'elize-' . Str::random(6) . '@hfcoastal.co.za',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Template::create([
            'name' => 'EATS V10 (CDS import shape)',
            'render_type' => 'web',
            'template_type' => 'cds',
            'category' => 'sales',
            'signing_parties' => ['owner_party', 'agent'],
            'field_mappings' => [],
            'owner_id' => $userId,
            'editor_state' => ['tagged_html' => $taggedHtml],
        ]);
    }

    /** @return array{0: string, 1: int} rendered html + legacy-clustering line count */
    private function renderCapturingLog(Template $t, string $html): array
    {
        $lines = 0;
        Log::listen(function ($e) use (&$lines) {
            if (str_contains((string) $e->message, self::LEGACY_LINE)) {
                $lines++;
            }
        });

        $out = app(RoleBlockExpansionService::class)->expandWithLooping($t, $html, $this->twoSellers());

        return [$out, $lines];
    }

    /**
     * NEGATIVE CONTROL. Without the contract the knife-edge line MUST fire — otherwise a
     * "zero legacy lines" result downstream would prove nothing at all.
     */
    public function test_negative_control_legacy_line_fires_without_the_contract(): void
    {
        $raw = $this->generatorShapedHtml();   // deliberately NOT normalised
        $t = $this->makeTemplate($raw);

        [, $lines] = $this->renderCapturingLog($t, $raw);

        $this->assertGreaterThan(0, $lines, 'the knife-edge signal must be wired, or the test below is worthless');
    }

    /** LEG 2 (was RED): the normalizer stamps the contract on real importer naming. */
    public function test_leg2_normalizer_stamps_the_contract_on_importer_naming(): void
    {
        $normalised = app(RoleBlockNormalizer::class)->normalize($this->generatorShapedHtml());

        $this->assertStringContainsString('data-role-block="seller"', $normalised);
        $this->assertGreaterThan(0, substr_count($normalised, 'data-role-block='));
    }

    /** LEG 3 (was RED): the renderer now takes the CONTRACT path — zero legacy lines. */
    public function test_leg3_renderer_takes_the_contract_path(): void
    {
        $normalised = app(RoleBlockNormalizer::class)->normalize($this->generatorShapedHtml());
        $t = $this->makeTemplate($normalised);

        [$rendered, $lines] = $this->renderCapturingLog($t, $normalised);

        $this->assertSame(0, $lines, 'THE KNIFE EDGE: the contract path must be taken — zero legacy clustering');
        $this->assertNotSame('', $rendered);
    }

    /** And it actually does the job: the seller block is cloned per recipient. */
    public function test_the_seller_block_is_cloned_for_both_sellers(): void
    {
        $normalised = app(RoleBlockNormalizer::class)->normalize($this->generatorShapedHtml());
        $t = $this->makeTemplate($normalised);

        [$rendered] = $this->renderCapturingLog($t, $normalised);

        $this->assertStringContainsString('__r2', $rendered, 'second seller s fields must be indexed');
        $this->assertGreaterThan(
            substr_count($normalised, 'data-role-block='),
            substr_count($rendered, 'data-role-block='),
            'role blocks must be cloned per recipient, not left as-is'
        );
    }

    /** The unassigned price field must NOT be dragged into a seller block. */
    public function test_an_unassigned_field_is_not_captured_by_a_role_block(): void
    {
        $normalised = app(RoleBlockNormalizer::class)->normalize($this->generatorShapedHtml());

        $this->assertMatchesRegularExpression(
            '/<p>Purchase price:(?![^<]*data-role-block)/',
            $normalised,
            'a manual/unassigned field must stay outside every role block'
        );
    }
}
