<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E-sign walk-fixes FIX 3 — post-save redirect lands on a valid URL.
 *
 * The bug: cdsGenerate redirected to `templates.index` after save, which
 * dropped the agent out of the CDS builder onto the template list page
 * — the walk-test framed this as a 404 because the user lost their
 * builder context entirely. The fix routes the redirect through
 * `templates.edit`, which provisions a fresh CdsDraft and returns the
 * agent to the builder for continued editing.
 *
 * The test posts to /docuperfect/templates/cds/generate with a real
 * authed user + a draft + the required form payload, then follows the
 * redirect chain. Asserts every step in the chain returns 200/302
 * (never 404), and the final destination is the CDS builder.
 */
final class CdsBuilderRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_cds_save_redirect_chain_ends_at_builder_with_200(): void
    {
        $user = $this->seedAgentWithTemplatePermissions();
        $template = DocuperfectTemplate::create([
            'name'           => 'Redirect Chain Template',
            'render_type'    => 'web',
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> ['owner_party'],
            'field_mappings' => [],
            'owner_id'       => $user->id,
            'cds_json'       => ['sections' => []],
        ]);
        $draft = CdsDraft::create([
            'user_id'            => $user->id,
            'agency_id'          => $user->agency_id ?? 1,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'mappings'           => [],
            'tags'               => [],
            'tagged_html'        => '<p>Body</p>',
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ]);

        // First hop — cdsGenerate.
        $resp = $this
            ->actingAs($user)
            ->from('/docuperfect/templates/cds/builder/' . $draft->id)
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id'      => $draft->id,
                'template_name' => $template->name,
                'is_esign'      => 1,
                'party_mode'    => 'shared',
                'allowed_delivery_modes' => 'esign',
                'security_tier' => 'enhanced',
                'signing_parties' => json_encode(['owner_party']),
                'category'      => 'sales',
                'document_type_id' => null,
            ]);

        // First hop must redirect (302), NOT 404.
        $this->assertNotSame(404, $resp->getStatusCode(), 'cdsGenerate must not 404 (was the walk-test bug)');
        $resp->assertRedirect();

        // Follow the redirect chain. Every hop must be 200 or another 302
        // — never 404.
        $hops = 0;
        $current = $resp;
        while ($current->isRedirect() && $hops < 5) {
            $hops++;
            $target = $current->headers->get('Location');
            $this->assertNotEmpty($target, 'Redirect target must not be empty');
            // The target is a full URL — extract the path portion.
            $path = parse_url($target, PHP_URL_PATH) ?? $target;
            $current = $this->actingAs($user)->get($path);
            $this->assertNotSame(404, $current->getStatusCode(),
                'Redirect chain hop ' . $hops . ' (' . $path . ') must not 404');
        }

        // Final destination — must be 200 AND must be the CDS builder.
        $current->assertOk();
        $final = $current->getRequest()->getRequestUri();
        $this->assertStringContainsString('/templates/cds/builder/', $final,
            'Post-save redirect must land on the CDS builder, not the template list — got ' . $final);
    }

    private function seedAgentWithTemplatePermissions(): User
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Agent Tester',
            'email' => 't-' . Str::random(8) . '@x.test',
            'password' => bcrypt('p'),
            'role' => 'agent',
            'is_admin' => 1,
            'agency_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::findOrFail($userId);
    }
}
