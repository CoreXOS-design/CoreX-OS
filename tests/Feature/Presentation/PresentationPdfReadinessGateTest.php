<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationAiVariant;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\AiSummaryService;
use App\Services\Presentations\PresentationCompilerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PDF readiness gate — covers the auto-summary build:
 *
 *   1. AiSummaryService::generateDefaultAndAccept — soft-fail
 *      semantics (NEVER throws; AI failures leave ai_summary_text
 *      null and the readiness gate then blocks the PDF).
 *   2. PresentationCompilerService — copy-forward of ai_summary_*
 *      fields from the most recent prior version onto a freshly-
 *      created version, preserving ai_summary_edited_by_agent.
 *   3. Controller defence-in-depth — Compile / Download PDF /
 *      Complete Pack ZIP all redirect with an error flash when
 *      ai_summary_text is null on the target version.
 *
 * Tests are AI-free: we never invoke a live AnthropicGateway. The
 * generateDefaultAndAccept happy path is exercised indirectly via
 * the soft-fail path; the compile copy-forward + gate tests seed
 * ai_summary_text directly so the gate has a deterministic input.
 */
final class PresentationPdfReadinessGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure both feature flags the controllers check are ON so
        // the gate logic runs (not the abort_unless 404 path).
        Config::set('features.presentation_blueprint', true);
        Config::set('features.presentation_pdf_v1', true);
    }

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── AiSummaryService::generateDefaultAndAccept ────────────────────

    public function test_generate_default_and_accept_never_throws_when_no_active_variant(): void
    {
        // Wipe variants so there's no default to pick — exercises the
        // early-return + warning-log branch.
        DB::table('presentation_ai_variants')->update(['is_active' => false]);

        [$presentation, $version, , , $user] = $this->seedPresentationAndUser();

        // Must not throw.
        (new AiSummaryService())->generateDefaultAndAccept($presentation, $version, $user);

        $version->refresh();
        $this->assertNull($version->ai_summary_text,
            'No active variant → method returns without writing summary text.');
    }

    public function test_generate_default_and_accept_never_throws_when_ai_fails(): void
    {
        // No ANTHROPIC_API_KEY in test env → AnthropicGateway emits a
        // fallback NarrativeResponse instead of throwing. Our method
        // detects from_fallback=true and skips acceptForVersion, so
        // ai_summary_text stays null and no exception bubbles up.
        Config::set('services.anthropic.api_key', '');

        [$presentation, $version, , , $user] = $this->seedPresentationAndUser();
        $this->seedVariant();

        (new AiSummaryService())->generateDefaultAndAccept($presentation, $version, $user);

        $version->refresh();
        $this->assertNull($version->ai_summary_text,
            'AI returned fallback → summary text stays null. Gate will block the PDF.');
    }

    // ── Compiler copy-forward ─────────────────────────────────────────

    public function test_compiler_copy_forwards_ai_summary_from_previous_version(): void
    {
        [$presentation, $v1, $agencyId, , $user] = $this->seedPresentationAndUser();
        $variantId = $this->seedVariant()->id;

        $v1->forceFill([
            'ai_summary_text'            => 'Hand-picked summary text from v1.',
            'ai_summary_raw_text'        => 'Raw AI output before any edit.',
            'ai_variant_id'              => $variantId,
            'ai_summary_edited_by_agent' => true,
            'ai_summary_generated_at'    => now()->subMinutes(5),
            'ai_summary_model'           => 'claude-sonnet-4-6',
            'ai_summary_prompt_hash'     => str_repeat('a', 64),
        ])->save();

        $v2 = (new PresentationCompilerService())->compile($presentation->id, $user->id);

        $this->assertSame('Hand-picked summary text from v1.', $v2->ai_summary_text);
        $this->assertSame('Raw AI output before any edit.', $v2->ai_summary_raw_text);
        $this->assertSame($variantId, $v2->ai_variant_id);
        $this->assertSame('claude-sonnet-4-6', $v2->ai_summary_model);
        $this->assertSame(str_repeat('a', 64), $v2->ai_summary_prompt_hash);
        $this->assertNotNull($v2->ai_summary_generated_at);
    }

    public function test_compiler_preserves_edited_by_agent_flag_across_versions(): void
    {
        [$presentation, $v1, , , $user] = $this->seedPresentationAndUser();
        $v1->forceFill([
            'ai_summary_text'            => 'Edited by the agent on v1.',
            'ai_summary_edited_by_agent' => true,
            'ai_summary_generated_at'    => now()->subMinutes(5),
        ])->save();

        $v2 = (new PresentationCompilerService())->compile($presentation->id, $user->id);

        $this->assertTrue((bool) $v2->ai_summary_edited_by_agent,
            'edited_by_agent flag must survive across versions (decision locked in the auto-summary build).');
    }

    public function test_compiler_leaves_null_when_no_previous_version_has_summary(): void
    {
        // Brand-new presentation, no prior version with summary. The
        // copy-forward path silently no-ops.
        [$presentation, , , , $user] = $this->seedPresentationAndUser();

        $v2 = (new PresentationCompilerService())->compile($presentation->id, $user->id);

        $this->assertNull($v2->ai_summary_text,
            'No prior summary to copy → new version has null ai_summary_text. Gate will block until generated.');
        $this->assertFalse((bool) $v2->ai_summary_edited_by_agent);
    }

    public function test_compiler_skips_versions_with_null_summary_when_copying_forward(): void
    {
        // Three versions exist: v1 has a summary, v2 has null (e.g. AI
        // was down at the time), v3 is the new one we're creating. v3
        // should copy v1's summary, NOT v2's null — i.e. the lookup
        // filters whereNotNull(ai_summary_text).
        [$presentation, $v1, , , $user] = $this->seedPresentationAndUser();
        $v1->forceFill([
            'ai_summary_text'        => 'Summary from v1.',
            'ai_summary_generated_at'=> now()->subMinutes(10),
        ])->save();
        // v2 with null summary
        PresentationVersion::create([
            'agency_id'          => $presentation->agency_id,
            'presentation_id'    => $presentation->id,
            'blueprint_version'  => 'test',
            'data_snapshot_json' => json_encode(['note' => 'v2 — no summary']),
            'compiled_at'        => now()->subMinutes(5),
        ]);

        $v3 = (new PresentationCompilerService())->compile($presentation->id, $user->id);

        $this->assertSame('Summary from v1.', $v3->ai_summary_text,
            'Copy-forward must skip null-summary versions and find the most recent non-null.');
    }

    // ── Controller defence-in-depth: Compile ──────────────────────────

    public function test_compile_endpoint_redirects_with_error_when_ai_summary_null(): void
    {
        [$presentation, $version, , , $user] = $this->seedPresentationAndUser();
        $this->actingAs($user);

        // Version exists but ai_summary_text is null — gate must block.
        $this->assertNull($version->ai_summary_text);

        $resp = $this->post(route('presentations.compile', $presentation));
        $resp->assertRedirect(route('presentations.show', $presentation));
        $resp->assertSessionHas('error');
        $this->assertStringContainsString('Executive Summary', (string) session('error'));
    }

    public function test_compile_endpoint_succeeds_when_ai_summary_present(): void
    {
        [$presentation, $version, , , $user] = $this->seedPresentationAndUser();
        $this->actingAs($user);
        $version->forceFill(['ai_summary_text' => 'Real summary'])->save();

        $resp = $this->post(route('presentations.compile', $presentation));
        $resp->assertRedirect(route('presentations.show', $presentation));
        $resp->assertSessionHas('success');
        $resp->assertSessionMissing('error');
    }

    // ── Controller defence-in-depth: PDF download ─────────────────────

    public function test_pdf_download_redirects_with_error_when_ai_summary_null(): void
    {
        [$presentation, $version, , , $user] = $this->seedPresentationAndUser();
        $this->actingAs($user);

        $resp = $this->get(route('presentations.versions.pdf', [$presentation, $version]));
        $resp->assertRedirect(route('presentations.show', $presentation));
        $resp->assertSessionHas('error');
        $this->assertStringContainsString('Executive Summary', (string) session('error'));
    }

    // ── Controller defence-in-depth: Complete Pack ZIP ────────────────

    public function test_complete_pack_redirects_with_error_when_ai_summary_null(): void
    {
        [$presentation, $version, , , $user] = $this->seedPresentationAndUser();
        $this->actingAs($user);

        $resp = $this->get(route('presentations.versions.complete-pack', [$presentation, $version]));
        $resp->assertRedirect(route('presentations.show', $presentation));
        $resp->assertSessionHas('error');
        $this->assertStringContainsString('Executive Summary', (string) session('error'));
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * Seed a presentation + a single (otherwise-empty) version + user.
     * @return array{0:Presentation, 1:PresentationVersion, 2:int, 3:Property, 4:User}
     */
    private function seedPresentationAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'PdfGate ' . Str::random(4),
            'slug' => 'pdfgate-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        $property = Property::create([
            'agency_id'     => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $user->id,
            'title'         => 'Subject',
            'property_type' => 'House',
            'category'      => 'Residential',
            'suburb'        => 'Testville',
            'price'         => 2_000_000,
            'beds'          => 3,
            'address'       => '1 Subject Way',
            'status'        => 'active',
            'listing_type'  => 'sale',
        ]);

        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $property->id,
            'created_by_user_id' => $user->id,
            'title'              => 'Pdf Gate Test',
            'property_address'   => $property->address,
            'suburb'             => 'Testville',
            'property_type'      => 'House',
            'asking_price_inc'   => 2_000_000,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);

        // PresentationSnapshot is required for compile()'s latestSnapshot
        // lookup, but the values don't matter for these tests.
        \App\Models\PresentationSnapshot::create([
            'presentation_id'         => $presentation->id,
            'generated_by_user_id'    => $user->id,
            'created_by_user_id'      => $user->id,
            'computed_json'           => '{}',
            'snapshot_json'           => '{}',
            'inputs_json'             => '{}',
            'output_summary_json'     => '{}',
            'generated_at'            => now(),
        ]);

        $version = PresentationVersion::create([
            'agency_id'          => $agencyId,
            'presentation_id'    => $presentation->id,
            'blueprint_version'  => 'test',
            'data_snapshot_json' => json_encode(['note' => 'pdf-gate-test']),
            'compiled_at'        => now(),
        ]);

        return [$presentation, $version, $agencyId, $property, $user];
    }

    private function seedVariant(): PresentationAiVariant
    {
        return PresentationAiVariant::create([
            'key'             => 'test_direct_' . Str::random(4),
            'display_name'    => 'Test Direct',
            'description'     => 'Test variant',
            'prompt_template' => 'TONE: Test. {facts_block}',
            'max_tokens'      => 800,
            'temperature'     => 0.40,
            'is_active'       => true,
            'sort_order'      => 1,
        ]);
    }
}
