<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-01 (Andre's security testing, handed to us by Johan) — pins the
 * cross-agency template leak fix in ESignWizardController.
 *
 * TemplateController calls Template::assertAccessibleBy() 14 times;
 * ESignWizardController called it ZERO times across 8 raw
 * Template::find()/findOrFail() lookups, so any authenticated agent —
 * regardless of agency — could read another agency's template structure
 * (name, page count, page-image URLs, full field/signature layout) via
 * testRender() and templatePages(). Confirmed live against HFC template #3;
 * page IMAGES themselves stayed blocked (guarded elsewhere), so this was a
 * structure leak, not a document-content leak.
 *
 * These two tests pin BOTH directions on the two endpoints Andre's report
 * named directly (testRender, templatePages): the owning agency keeps
 * working exactly as before (200, real data), and a different agency now
 * gets a 404 — not a 403, matching TemplateController's own
 * existence-hiding convention (assertAccessibleBy() itself decides that).
 *
 * Not re-testing assertAccessibleBy()'s own is_global/branch/owner-role
 * rules here — those are Template's own tests (DocumentAgencyIsolationTest,
 * TemplateCreationDefaultsTest). This file only pins that
 * ESignWizardController actually CALLS the guard at both sites.
 */
class ESignWizardControllerAgencyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function agencyWithUser(string $label): array
    {
        $agency = Agency::create(['name' => "Agency {$label}", 'slug' => 'agency-' . strtolower($label) . '-' . uniqid()]);
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => "Branch {$label}"]);

        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'access_docuperfect', 'agency_id' => $agency->id],
            []
        );

        $user = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
            'is_active' => true,
        ]);

        return [$agency, $user];
    }

    private function ownedTemplate(Agency $agency, User $owner): Template
    {
        return Template::create([
            'name'        => "{$agency->name} Mandate",
            'owner_id'    => $owner->id,
            'agency_id'   => $agency->id,
            'is_global'   => false,
            'page_count'  => 1,
            'fields_json' => [],
        ]);
    }

    public function test_test_render_returns_own_agency_template_but_404s_another_agencys(): void
    {
        [$agencyA, $userA] = $this->agencyWithUser('A');
        [, $userB] = $this->agencyWithUser('B');
        PermissionService::clearCache();

        $template = $this->ownedTemplate($agencyA, $userA);

        // Owning agency — unchanged behaviour, real data.
        $this->actingAs($userA)
            ->get(route('docuperfect.esign.testRender', ['templateId' => $template->id]))
            ->assertOk()
            ->assertSee($template->name);

        // A different agency — must 404, not 403 (existence must not leak),
        // and must never reach the view with the other agency's template data.
        $this->actingAs($userB)
            ->get(route('docuperfect.esign.testRender', ['templateId' => $template->id]))
            ->assertNotFound();
    }

    public function test_template_pages_api_returns_own_agency_template_but_404s_another_agencys(): void
    {
        [$agencyA, $userA] = $this->agencyWithUser('A');
        [, $userB] = $this->agencyWithUser('B');
        PermissionService::clearCache();

        $template = $this->ownedTemplate($agencyA, $userA);

        // Owning agency — unchanged behaviour: the real JSON payload (name,
        // page_count, pages, fields) an agent's own wizard preview needs.
        $response = $this->actingAs($userA)
            ->getJson(route('docuperfect.esign.api.templatePages', ['templateId' => $template->id]))
            ->assertOk();
        $response->assertJsonPath('name', $template->name);
        $response->assertJsonPath('render_type', 'pdf');

        // A different agency — this is the exact endpoint Andre's report
        // confirmed leaking (200 with HFC's name/page-count/fields/field-layout
        // JSON for a Demo Agency Test user). Must now 404.
        $this->actingAs($userB)
            ->getJson(route('docuperfect.esign.api.templatePages', ['templateId' => $template->id]))
            ->assertNotFound();
    }
}
