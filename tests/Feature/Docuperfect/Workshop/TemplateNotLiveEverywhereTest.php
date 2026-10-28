<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\Template;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13.6 — a released copy that has not been approved is not live ANYWHERE: every list, picker,
 * launcher, guard and direct URL refuses it, and it can be looked at only by the owner and the agency's authorisers.
 */
final class TemplateNotLiveEverywhereTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    /** @return array{0: Template, 1: \App\Models\Agency, 2: \App\Models\User, 3: \App\Models\User} copy, client, owner, client admin */
    private function awaiting(): array
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [, $copy] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);

        return [$copy->fresh(), $client, $owner, $this->agencyAdmin($client)];
    }

    public function test_the_management_list_the_pickers_and_the_edit_url_do_not_show_or_open_it(): void
    {
        [$copy, , $owner, $admin] = $this->awaiting();

        $html = $this->actingAs($admin)->get(route('docuperfect.templates.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($copy->name, $html, 'not on the agency admin\'s Template Management list');
        $this->actingAs($admin)->get(route('docuperfect.templates.edit', $copy->id))->assertNotFound();
        $this->actingAs($admin)->get(route('docuperfect.templates.webPreview', $copy->id))->assertNotFound();
        $this->assertFalse(Template::active()->visibleTo($admin)->whereKey($copy->id)->exists());
        $this->assertFalse(Template::active()->visibleTo($owner)->whereKey($copy->id)->exists(), 'not even in the owner\'s pickers');
    }

    public function test_a_document_cannot_be_started_from_it_by_anyone(): void
    {
        [$copy, , $owner, $admin] = $this->awaiting();

        $this->actingAs($admin)->post(route('docuperfect.esign.store'), ['template_id' => $copy->id])->assertNotFound();
        $this->actingAs($owner)->post(route('docuperfect.esign.store'), ['template_id' => $copy->id])->assertNotFound();
        $this->assertSame(0, \DB::table('flows')->where('template_id', $copy->id)->count());
    }

    public function test_the_lease_guard_refuses_it_and_the_lease_agreement_picker_does_not_offer_it(): void
    {
        [$copy, $client, , $admin] = $this->awaiting();

        $refusals = app(LeaseAgreementTemplateGuard::class)->refusalsFor($copy, (int) $client->id);
        $this->assertContains(LeaseAgreementTemplateGuard::AWAITING, $refusals);
        $this->assertContains(LeaseAgreementTemplateGuard::AWAITING, app(LeaseAgreementTemplateGuard::class)->problemsFor($copy, (int) $client->id));

        $lease = $this->agencyManager($client, ['rental_lease_templates.manage_settings'], 'lease_admin');
        $html = $this->actingAs($lease)->get(route('corex.rental-lease-templates.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="' . $copy->id . '"', $html);
    }

    public function test_readiness_is_open_to_the_owner_and_the_agencys_authorisers(): void
    {
        [$copy, , $owner, $admin] = $this->awaiting();
        $url = route('docuperfect.templates.readiness', $copy->id);

        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($admin)->get($url)->assertOk();   // admin fallback: nobody named yet
    }

    public function test_readiness_is_closed_to_an_agency_manager_who_is_not_an_authoriser(): void
    {
        [$copy, $client] = $this->awaiting();
        $plain = $this->agencyManager($client, [], 'branch_manager');   // manages templates, is not an authoriser

        $this->actingAs($plain)->get(route('docuperfect.templates.readiness', $copy->id))->assertNotFound();
    }

    public function test_readiness_is_closed_to_another_agencys_admin(): void
    {
        [$copy] = $this->awaiting();
        $foreign = $this->agencyAdmin($this->agency('Another Agency'));

        $this->actingAs($foreign)->get(route('docuperfect.templates.readiness', $copy->id))->assertNotFound();
    }

    public function test_once_approved_it_is_a_normal_live_template_everywhere(): void
    {
        [$copy, $client, , $admin] = $this->awaiting();
        $release = \App\Models\Docuperfect\TemplateRelease::withoutGlobalScopes()->where('template_id', $copy->id)->first();
        app(\App\Services\Docuperfect\Release\TemplateReleaseService::class)->approve($release, $admin, true);

        $live = $copy->fresh();
        $this->assertFalse($live->isHeldBack());
        $this->assertTrue(Template::active()->visibleTo($admin)->whereKey($live->id)->exists());
        $this->assertNotContains(LeaseAgreementTemplateGuard::AWAITING, app(LeaseAgreementTemplateGuard::class)->refusalsFor($live, (int) $client->id));
        $this->assertNotSame(404, $this->actingAs($admin)->get(route('docuperfect.templates.edit', $live->id))->getStatusCode(), 'the agency can open it again');
    }

    public function test_an_ordinary_template_is_untouched_by_all_of_this(): void
    {
        $hfc = $this->agency('Home Finders Coastal');
        $owner = $this->owner();
        $t = $this->megaShaped($hfc, $owner, ['name' => 'Existing HFC template']);
        $admin = $this->agencyAdmin($hfc);

        $this->assertNull($t->fresh()->release_status);
        $this->assertFalse($t->fresh()->isHeldBack());
        $this->assertTrue(Template::active()->visibleTo($admin)->whereKey($t->id)->exists());
        $this->assertNotSame(404, $this->actingAs($admin)->get(route('docuperfect.templates.edit', $t->id))->getStatusCode());
    }
}
