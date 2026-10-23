<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Template Settings > DOCUMENT SIGNING ROLES must round-trip.
 *
 * The bug (QA1, template 82 "Mega Lease Agreement 2026"): the agent ticked
 * "Lessee / Tenant", pressed Save Template, saw "Template saved", reloaded and
 * the tick was gone (Lessor and Agent, the builder's defaults, were back).
 *
 * The save was fine — templates.signing_parties held all three roles. The
 * reload was not: Save redirects to the builder, which reopens through
 * TemplateController::resolveCdsEditingDraft(). That seeded the fresh draft's
 * settings from the template for every key the builder saves EXCEPT
 * signing_parties, so the page fell back to its hard-coded default
 * ['owner_party', 'agent'] — and the next Save would have written that default
 * over the real roles.
 *
 * The contract pinned here: whatever role set the template holds is what the
 * reopened builder is handed — acquiring_party, witness, order and all.
 */
final class CdsBuilderSigningRolesRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: array<int,string>}> */
    public static function roleSets(): array
    {
        return [
            'owner + agent (the builder default)'        => [['owner_party', 'agent']],
            'owner + acquiring + agent (a lease)'        => [['owner_party', 'agent', 'acquiring_party']],
            'acquiring party alone'                      => [['acquiring_party']],
            'every role incl. witness'                   => [['owner_party', 'acquiring_party', 'agent', 'witness']],
        ];
    }

    /**
     * Save through the real cdsGenerate endpoint, follow the redirect to the
     * builder, and read back what the page was handed.
     *
     * @dataProvider roleSets
     * @param array<int,string> $roles
     */
    public function test_saved_roles_come_back_when_the_builder_reopens(array $roles): void
    {
        $user = $this->seedAgentWithTemplatePermissions();
        [$template, $draft] = $this->makeTemplateAndDraft($user);

        $resp = $this->actingAs($user)
            ->from('/docuperfect/templates/cds/builder/' . $draft->id)
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id'               => $draft->id,
                'template_name'          => $template->name,
                'is_esign'               => 1,
                'party_mode'             => 'shared',
                'allowed_delivery_modes' => 'esign',
                'security_tier'          => 'enhanced',
                'signing_parties'        => json_encode($roles),
                'category'               => 'rentals',
                'document_type_id'       => null,
            ]);
        $resp->assertRedirect();

        // The save itself must have stored them.
        $stored = DocuperfectTemplate::query()->where('name', $template->name)->latest('id')->firstOrFail();
        $this->assertSame($roles, $stored->signing_parties, 'The save must store the roles ticked');

        // ...and the builder it lands on must show them.
        $path = parse_url($resp->headers->get('Location') ?? '', PHP_URL_PATH) ?? '';
        $builder = $this->actingAs($user)->get($path);
        $builder->assertOk();

        $this->assertSame(
            $roles,
            $builder->viewData('savedSettings')['signing_parties'] ?? null,
            'The reopened builder must be handed the saved roles — otherwise it shows its default '
            . 'and a Lessee/Tenant tick appears to vanish on reload'
        );
    }

    /** Opening a template for editing (Edit button, not Save) takes the same seeding path. */
    public function test_opening_a_template_for_edit_seeds_every_setting_the_builder_saves(): void
    {
        $user = $this->seedAgentWithTemplatePermissions();
        [$template] = $this->makeTemplateAndDraft($user);
        $template->update([
            'signing_parties' => ['owner_party', 'agent', 'acquiring_party'],
            'category'        => 'rentals',
        ]);
        // No reusable draft: the only one is older than the template, so it is retired.
        CdsDraft::query()->update(['status' => 'saved']);

        $resp = $this->actingAs($user)->get('/docuperfect/templates/' . $template->id . '/edit');
        $resp->assertRedirect();
        $builder = $this->actingAs($user)->get(parse_url($resp->headers->get('Location'), PHP_URL_PATH));
        $builder->assertOk();

        $settings = $builder->viewData('savedSettings');
        $this->assertSame(['owner_party', 'agent', 'acquiring_party'], $settings['signing_parties'] ?? null);

        // Drift guard: the keys the builder POSTs on save and the keys the seed supplies
        // must be the same set (cds-builder.blade.php _doSaveDraft settings{}).
        foreach (['is_esign', 'party_mode', 'allowed_delivery_modes', 'security_tier',
                  'signing_parties', 'category', 'document_type_id'] as $key) {
            $this->assertArrayHasKey($key, $settings, "Reopened builder is not seeded with '{$key}'");
        }
    }

    /**
     * A draft seeded BEFORE the fix (QA1 template 82, draft 55) has no signing_parties key and is
     * still reused when it is at least as new as the template. Reopening it must backfill the
     * roles from the template rather than hand the page its default.
     */
    public function test_a_reused_draft_missing_the_roles_is_backfilled_from_the_template(): void
    {
        $user = $this->seedAgentWithTemplatePermissions();
        [$template, $draft] = $this->makeTemplateAndDraft($user);
        $template->update(['signing_parties' => ['owner_party', 'agent', 'acquiring_party']]);
        $draft->forceFill(['settings' => ['category' => 'rentals', 'is_esign' => true], 'updated_at' => now()->addMinute()])
            ->saveQuietly();

        $resp = $this->actingAs($user)->get('/docuperfect/templates/' . $template->id . '/edit');
        $resp->assertRedirect();
        $this->assertStringEndsWith('/builder/' . $draft->id, $resp->headers->get('Location'), 'the existing draft must be the one reused');

        $builder = $this->actingAs($user)->get(parse_url($resp->headers->get('Location'), PHP_URL_PATH));
        $builder->assertOk();
        $this->assertSame(['owner_party', 'agent', 'acquiring_party'], $builder->viewData('savedSettings')['signing_parties'] ?? null);
        $this->assertTrue((bool) ($builder->viewData('savedSettings')['is_esign'] ?? false), 'other saved settings must be left alone');
    }

    /** @return array{0: DocuperfectTemplate, 1: CdsDraft} */
    private function makeTemplateAndDraft(User $user): array
    {
        $template = DocuperfectTemplate::create([
            'name'            => 'Roles Round Trip ' . Str::random(6),
            'render_type'     => 'web',
            'template_type'   => 'cds',
            'category'        => 'rentals',
            'signing_parties' => ['owner_party', 'agent'],
            'field_mappings'  => [],
            'owner_id'        => $user->id,
            'cds_json'        => ['sections' => []],
        ]);
        $draft = CdsDraft::create([
            'user_id'            => $user->id,
            'agency_id'          => $user->agency_id,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'mappings'           => [],
            'tags'               => [],
            'tagged_html'        => '<p>Body</p>',
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ]);

        return [$template, $draft];
    }

    private function seedAgentWithTemplatePermissions(): User
    {
        // Owner-flagged role so the permission check shortcuts to true; Role caches its
        // rows statically, so clearCache() is required after the insert.
        DB::table('roles')->insertOrIgnore([
            'name' => 'test_template_owner',
            'label' => 'Test Template Owner',
            'is_owner' => true,
            'can_be_deleted' => false,
            'sort_order' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Models\Role::clearCache();
        // A real agency row: users.agency_id is a foreign key and the schema snapshot carries no rows.
        $agency = Agency::create(['name' => 'Roles Test Agency', 'slug' => 'roles-test-' . Str::random(8)]);
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Agent Tester',
            'email' => 't-' . Str::random(8) . '@x.test',
            'password' => bcrypt('p'),
            'role' => 'test_template_owner',
            'is_admin' => 1,
            'agency_id' => $agency->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($userId);
    }
}
