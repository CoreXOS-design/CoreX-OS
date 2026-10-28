<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Agency;
use App\Models\Docuperfect\TemplateReleaseEvent;
use App\Models\User;
use App\Services\Docuperfect\Release\AgencyDocumentAuthorisers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13.3 — "Who may authorise documents": the saver (people + roles), the has()-guarded subset posts
 * (screen and Setup Wizard), foreign ids refused, the admin fallback, the permission floor, the owner never approves, and the screen.
 */
final class DocumentAuthoriserSettingTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    public function test_both_lists_save_and_the_change_is_logged(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $admin = $this->agencyAdmin($client);
        $agent = $this->agencyUserWithRole($client, 'agent');
        $this->agencyUserWithRole($client, 'branch_manager');   // the role exists for the agency

        $this->actingAs($admin)->post(route('docuperfect.esign.settings.document-authorisers.update'), [
            'document_authoriser_user_ids' => ['', (string) $agent->id],
            'document_authoriser_roles' => ['', 'branch_manager'],
        ])->assertRedirect(route('docuperfect.esign.settings.document-authorisers'));

        $s = AgencyDocumentAuthorisers::settings($client->id);
        $this->assertSame([(int) $agent->id], $s['user_ids']);
        $this->assertSame(['branch_manager'], $s['roles']);
        $ev = TemplateReleaseEvent::where('event', 'authorisers_changed')->first();
        $this->assertSame((int) $client->id, (int) $ev->agency_id);
        $this->assertSame([], $ev->detail['before']['user_ids']);
        $this->assertSame('settings', $ev->detail['source']);
    }

    public function test_unticking_everyone_clears_the_lists_and_restores_the_admin_fallback(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $admin = $this->agencyAdmin($client);
        $agent = $this->agencyUserWithRole($client, 'agent');
        $this->agencyUserWithRole($client, 'branch_manager');
        $svc = app(AgencyDocumentAuthorisers::class);
        $svc->save($client->id, [$agent->id], ['branch_manager'], $admin);
        $this->assertTrue(AgencyDocumentAuthorisers::isConfigured($client->id));

        $this->actingAs($admin)->post(route('docuperfect.esign.settings.document-authorisers.update'), ['document_authoriser_user_ids' => [''], 'document_authoriser_roles' => ['']]);
        $this->assertFalse(AgencyDocumentAuthorisers::isConfigured($client->id));
        $this->assertTrue(AgencyDocumentAuthorisers::canAuthorise($admin, $client->id), 'back to the administrators');
    }

    public function test_a_post_with_only_one_list_leaves_the_other_alone(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $admin = $this->agencyAdmin($client);
        $agent = $this->agencyUserWithRole($client, 'agent');
        $this->agencyUserWithRole($client, 'branch_manager');
        app(AgencyDocumentAuthorisers::class)->save($client->id, [$agent->id], ['branch_manager'], $admin);

        // Screen: only the people list posted.
        $this->actingAs($admin)->post(route('docuperfect.esign.settings.document-authorisers.update'), ['document_authoriser_user_ids' => [(string) $admin->id]]);
        $s = AgencyDocumentAuthorisers::settings($client->id);
        $this->assertSame([(int) $admin->id], $s['user_ids']);
        $this->assertSame(['branch_manager'], $s['roles'], 'roles were not posted, so they are untouched');

        // Wizard: only the roles control (with its _submitted marker) posted.
        $req = Request::create('/agency-setup', 'POST', ['document_authoriser_roles_submitted' => '1', 'document_authoriser_roles' => ['admin']]);
        $req->setUserResolver(fn () => $admin);
        app(\App\Http\Controllers\Docuperfect\DocumentAuthoriserSettingsController::class)->saveFromWizard($req);
        $s = AgencyDocumentAuthorisers::settings($client->id);
        $this->assertSame(['admin'], $s['roles']);
        $this->assertSame([(int) $admin->id], $s['user_ids'], 'people were not posted, so they are untouched');

        // Wizard: a step that posts neither control changes nothing and writes no log row.
        $before = TemplateReleaseEvent::count();
        $req2 = Request::create('/agency-setup', 'POST', ['something_else' => '1']);
        $req2->setUserResolver(fn () => $admin);
        app(\App\Http\Controllers\Docuperfect\DocumentAuthoriserSettingsController::class)->saveFromWizard($req2);
        $this->assertSame($before, TemplateReleaseEvent::count());
        $this->assertSame(['admin'], AgencyDocumentAuthorisers::settings($client->id)['roles']);
    }

    public function test_a_wizard_post_without_the_permission_is_a_403_before_any_write(): void
    {
        $client = $this->agency('Cape Town Lettings');
        \App\Services\PermissionService::forceProductionPosture();
        $nobody = User::factory()->create(['agency_id' => $client->id, 'role' => 'agent', 'is_active' => true]);
        $req = Request::create('/agency-setup', 'POST', ['document_authoriser_user_ids' => [(string) $nobody->id]]);
        $req->setUserResolver(fn () => $nobody);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        try {
            app(\App\Http\Controllers\Docuperfect\DocumentAuthoriserSettingsController::class)->saveFromWizard($req);
        } finally {
            $this->assertFalse(AgencyDocumentAuthorisers::isConfigured($client->id));
        }
    }

    public function test_a_user_id_from_another_agency_is_refused_and_nothing_changes(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $other = $this->agency('Another Agency');
        $admin = $this->agencyAdmin($client);
        $stranger = $this->agencyAdmin($other);

        $this->actingAs($admin)->post(route('docuperfect.esign.settings.document-authorisers.update'), ['document_authoriser_user_ids' => ['', (string) $stranger->id]])
            ->assertSessionHasErrors('authorisers');
        $this->assertFalse(AgencyDocumentAuthorisers::isConfigured($client->id));
        $this->assertSame(0, TemplateReleaseEvent::where('event', 'authorisers_changed')->count());
    }

    public function test_an_unknown_or_owner_role_is_refused(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $admin = $this->agencyAdmin($client);
        $this->owner();   // makes the super_admin owner role exist

        $this->actingAs($admin)->post(route('docuperfect.esign.settings.document-authorisers.update'), ['document_authoriser_roles' => ['', 'wizard']])->assertSessionHasErrors('authorisers');
        $this->actingAs($admin)->post(route('docuperfect.esign.settings.document-authorisers.update'), ['document_authoriser_roles' => ['', 'super_admin']])->assertSessionHasErrors('authorisers');
        $this->assertFalse(AgencyDocumentAuthorisers::isConfigured($client->id));
    }

    public function test_can_authorise_rules(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $other = $this->agency('Another Agency');
        $admin = $this->agencyAdmin($client);
        $agent = $this->agencyUserWithRole($client, 'agent');
        $owner = $this->owner();
        $foreignAdmin = $this->agencyAdmin($other);

        $this->assertTrue(AgencyDocumentAuthorisers::canAuthorise($admin, $client->id), 'admin fallback while nobody is named');
        $this->assertFalse(AgencyDocumentAuthorisers::canAuthorise($agent, $client->id));
        $this->assertFalse(AgencyDocumentAuthorisers::canAuthorise($owner, $client->id), 'the owner never approves for an agency');
        $this->assertFalse(AgencyDocumentAuthorisers::canAuthorise($foreignAdmin, $client->id), 'another agency\'s admin never does');
        $this->assertFalse(AgencyDocumentAuthorisers::canAuthorise(null, $client->id));
        $this->assertFalse(AgencyDocumentAuthorisers::canAuthorise($admin, 0));

        app(AgencyDocumentAuthorisers::class)->save($client->id, [$agent->id], null, $admin);
        $this->assertTrue(AgencyDocumentAuthorisers::canAuthorise($agent, $client->id));
        $this->assertFalse(AgencyDocumentAuthorisers::canAuthorise($admin, $client->id), 'once someone is named, an unnamed admin cannot');
        $this->assertSame([(int) $agent->id], AgencyDocumentAuthorisers::authorisers($client->id)->pluck('id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_the_settings_screen_loads_shows_the_fallback_notice_and_warns_about_a_role_without_the_key(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $admin = $this->agencyAdmin($client);
        $keyless = $this->agencyUserWithRole($client, 'bookkeeper', []);

        $html = $this->actingAs($admin)->get(route('docuperfect.esign.settings.document-authorisers'))->assertOk()->getContent();
        $this->assertStringContainsString('data-not-configured', $html);
        $this->assertStringContainsString('Who may authorise documents', $html);

        app(AgencyDocumentAuthorisers::class)->save($client->id, [$keyless->id], null, $admin);
        $html = $this->actingAs($admin)->get(route('docuperfect.esign.settings.document-authorisers'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-not-configured', $html);
        $this->assertStringContainsString('data-missing-permission', $html);
        $this->assertStringContainsString($keyless->name, $html);
    }

    public function test_the_settings_screen_needs_the_esign_settings_permission(): void
    {
        $client = $this->agency('Cape Town Lettings');
        \App\Services\PermissionService::forceProductionPosture();
        $plain = User::factory()->create(['agency_id' => $client->id, 'role' => 'agent', 'is_active' => true]);
        $this->actingAs($plain)->get(route('docuperfect.esign.settings.document-authorisers'))->assertForbidden();
        $this->actingAs($plain)->post(route('docuperfect.esign.settings.document-authorisers.update'), ['document_authoriser_roles' => ['admin']])->assertForbidden();
    }

    public function test_the_wizard_config_carries_the_two_controls_the_saver_and_an_arm_per_control(): void
    {
        $step = config('agency-onboarding-copy.compliance');
        $keys = array_column($step['controls'], 'key');
        $this->assertContains('document_authoriser_user_ids', $keys);
        $this->assertContains('document_authoriser_roles', $keys);
        foreach ($step['controls'] as $c) {
            if (in_array($c['key'], ['document_authoriser_user_ids', 'document_authoriser_roles'], true)) {
                $this->assertNotSame('', trim($c['explain']));
                $this->assertNotSame('', trim($c['affects']));
                $this->assertSame('document_authorisers', $c['source']);
            }
        }
        $savers = array_map(fn ($s) => $s['controller'] . '@' . $s['method'], $step['savers']);
        $this->assertContains(\App\Http\Controllers\Docuperfect\DocumentAuthoriserSettingsController::class . '@saveFromWizard', $savers);
    }
}
