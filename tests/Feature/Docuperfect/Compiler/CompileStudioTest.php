<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler;

use App\Models\Docuperfect\CompiledTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-177 / WS4-S — the Compile Studio drives cc2's WS4-E engine end to end through the HTTP
 * layer: start a draft → lint (L1–L7, L6 live) → certify (golden) → publish an immutable version.
 * The reference proof 117 (zero-field) is the clean end-to-end path; 116 exercises field binding.
 */
final class CompileStudioTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOperator(): User
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'D', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin', 'email_verified_at' => now(),
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_studio_home_renders(): void
    {
        $this->actingAsOperator();
        $this->withoutVite();

        $this->get(route('docuperfect.compiler.index'))
            ->assertOk()
            ->assertSee('Compile Studio');
    }

    public function test_start_from_reference_creates_a_draft(): void
    {
        $this->actingAsOperator();

        $res = $this->post(route('docuperfect.compiler.start'), ['source' => 'reference', 'reference' => '117']);

        $draft = CompiledTemplate::query()->where('status', CompiledTemplate::STATUS_DRAFT)->first();
        $this->assertNotNull($draft, 'a draft was created');
        $this->assertSame('117', $draft->family);
        $res->assertRedirect(route('docuperfect.compiler.studio', $draft->id));
    }

    public function test_studio_workbench_renders_for_a_draft(): void
    {
        $this->actingAsOperator();
        $this->withoutVite();
        $this->post(route('docuperfect.compiler.start'), ['source' => 'reference', 'reference' => '117']);
        $draft = CompiledTemplate::query()->where('status', CompiledTemplate::STATUS_DRAFT)->firstOrFail();

        $this->get(route('docuperfect.compiler.studio', $draft->id))
            ->assertOk()
            ->assertSee('compileStudio', false); // the Alpine component boots
    }

    public function test_lint_certify_publish_flow_on_the_117_reference(): void
    {
        $this->actingAsOperator();
        $this->post(route('docuperfect.compiler.start'), ['source' => 'reference', 'reference' => '117']);
        $draft = CompiledTemplate::query()->where('status', CompiledTemplate::STATUS_DRAFT)->firstOrFail();

        // Lint — 117 is a valid zero-field CDS (L1 vacuous, L3 anchors per party, L6 parity live).
        $lint = $this->postJson(route('docuperfect.compiler.lint', $draft->id));
        $lint->assertOk()->assertJsonPath('lint.publishable', true);
        $this->assertSame(CompiledTemplate::LINT_PASSED, $draft->fresh()->lint_status);

        // Certify — golden harness certifies every party combination.
        $this->postJson(route('docuperfect.compiler.certify', $draft->id))
            ->assertOk()->assertJsonPath('golden.certifiable', true);

        // Publish — immutable, content-hashed version.
        $pub = $this->postJson(route('docuperfect.compiler.publish', $draft->id));
        $pub->assertOk()->assertJsonPath('ok', true);

        $published = CompiledTemplate::query()->where('status', CompiledTemplate::STATUS_PUBLISHED)->first();
        $this->assertNotNull($published);
        $this->assertSame('117', $published->family);
        $this->assertNotEmpty($published->content_hash);
        $this->assertSame(1, $published->version);
    }

    public function test_publish_is_blocked_when_the_gate_is_not_clean(): void
    {
        $this->actingAsOperator();
        $this->post(route('docuperfect.compiler.start'), ['source' => 'reference', 'reference' => '117']);
        $draft = CompiledTemplate::query()->where('status', CompiledTemplate::STATUS_DRAFT)->firstOrFail();

        // Declare an extra party ("witness") with NO signature surface → linter L3 (every declared
        // party owns a signing anchor) now fails, so the draft is un-publishable.
        $this->postJson(route('docuperfect.compiler.declareParty', $draft->id), [
            'party' => ['key' => 'witness', 'role' => 'Witness', 'cardinality' => 'one', 'ordering' => 9],
        ])->assertOk();

        // publish() re-runs the gate; a party with no anchor is rejected with a user-clear 422
        // (naming the blocking rule), never a 500, and nothing is published.
        $this->postJson(route('docuperfect.compiler.publish', $draft->id))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(0, CompiledTemplate::query()->where('status', CompiledTemplate::STATUS_PUBLISHED)->count());
    }

    public function test_bind_field_binds_a_fill_point_on_116(): void
    {
        if (! method_exists(\App\Support\Docuperfect\Cds\Reference\ReferencePackCds::class, 'template116')) {
            $this->markTestSkipped('Reference 116 (field-bearing) not available in this tree.');
        }
        $this->actingAsOperator();
        $this->post(route('docuperfect.compiler.start'), ['source' => 'reference', 'reference' => '116']);
        $draft = CompiledTemplate::query()->where('status', CompiledTemplate::STATUS_DRAFT)->firstOrFail();

        // Find the first field in the CDS.
        $block = collect($draft->structure['blocks'])->first(fn ($b) => ! empty($b['fields']));
        $this->assertNotNull($block, '116 has at least one field');
        $field = $block['fields'][0];

        // Bind it to a dictionary key and assert the binding persisted in the structure.
        $res = $this->postJson(route('docuperfect.compiler.bindField', $draft->id), [
            'block_id' => $block['block_id'],
            'field_id' => $field['field_id'],
            'dictionary_key' => 'seller_full_name',
        ]);
        $res->assertOk()->assertJsonPath('ok', true);

        $draft->refresh();
        $bound = collect($draft->structure['blocks'])
            ->firstWhere('block_id', $block['block_id'])['fields'][0]['binding'] ?? null;
        $this->assertSame('seller_full_name', $bound);
    }
}
