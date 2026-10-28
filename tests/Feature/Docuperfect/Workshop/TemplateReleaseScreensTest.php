<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateRelease;
use App\Models\User;
use App\Services\Docuperfect\Release\TemplateReleaseService;
use App\Support\Navigation\Hubs\DocumentTemplatesTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13.4–13.5 — the Releases tab (owner) and the Approvals tab (agency): search, sort, filter, pagination,
 * empty states, the verify / release / approve / decline / withdraw actions through the real routes, 404 across agencies by direct URL, 403
 * without the permission, the owner seeing Approvals read-only, and the hub tabs.
 */
final class TemplateReleaseScreensTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    private function svc(): TemplateReleaseService
    {
        return app(TemplateReleaseService::class);
    }

    // ── permissions and tabs ──────────────────────────────────────────────────

    public function test_releases_is_owner_only_and_an_agency_admin_gets_403_even_with_the_approve_key(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $admin = $this->agencyAdmin($client);
        $owner = $this->owner();

        $this->actingAs($admin)->get(route('docuperfect.template-releases.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('docuperfect.templates.verify', 1), ['i_have_looked' => 1])->assertForbidden();
        $this->actingAs($admin)->post(route('docuperfect.templates.release', 1), ['agency_id' => $client->id])->assertForbidden();
        $this->actingAs($admin)->post(route('docuperfect.template-releases.withdraw', 1))->assertForbidden();
        $this->actingAs($owner)->get(route('docuperfect.template-releases.index'))->assertOk();
    }

    public function test_the_hub_shows_releases_to_the_owner_and_approvals_to_authorisers_only(): void
    {
        $client = $this->agency('Cape Town Lettings');
        $admin = $this->agencyAdmin($client);
        $agent = $this->agencyUserWithRole($client, 'agent', ['manage_templates']);   // can manage templates, is not an authoriser
        $owner = $this->owner();

        $this->assertArrayHasKey('releases', DocumentTemplatesTabs::forUser($owner));
        $this->assertArrayHasKey('approvals', DocumentTemplatesTabs::forUser($owner), 'the owner sees it read-only');
        $this->assertArrayNotHasKey('releases', DocumentTemplatesTabs::forUser($admin));
        $this->assertArrayHasKey('approvals', DocumentTemplatesTabs::forUser($admin), 'admin fallback while nobody is named');
        $this->assertArrayNotHasKey('approvals', DocumentTemplatesTabs::forUser($agent));
        $this->actingAs($agent)->get(route('docuperfect.template-approvals.index'))->assertForbidden();
    }

    public function test_the_approvals_tab_carries_the_waiting_count(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $admin = $this->agencyAdmin($client);
        $this->assertNull(DocumentTemplatesTabs::forUser($admin)['approvals']['badge']);
        $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);
        $this->assertSame(1, DocumentTemplatesTabs::forUser($admin)['approvals']['badge']);
    }

    // ── the workshop actions through the real routes ──────────────────────────

    public function test_verify_and_release_through_the_routes(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner, ['name' => 'Cape Lease 2026']);

        $html = $this->actingAs($owner)->get(route('docuperfect.workshop.index', ['q' => 'Cape Lease']))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-row="' . $t->id . '"', $html);
        $this->assertStringContainsString('data-verified-state="workshop"', $html);

        // No tick -> refused with a plain message, nothing stored.
        $this->actingAs($owner)->from(route('docuperfect.template-releases.index'))->post(route('docuperfect.templates.verify', $t->id))->assertSessionHasErrors('release');
        $this->assertNull($t->fresh()->workshop_verified_at);

        $this->actingAs($owner)->post(route('docuperfect.templates.verify', $t->id), ['i_have_looked' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($t->fresh()->isVerifiedAndCurrent());
        $this->assertStringContainsString('data-verified-state="verified"', $this->actingAs($owner)->get(route('docuperfect.workshop.index', ['q' => 'Cape Lease']))->getContent());

        // Missing agency -> plain message; the same agency -> refused; the client -> released, held back.
        $this->actingAs($owner)->post(route('docuperfect.templates.release', $t->id), [])->assertSessionHasErrors('agency_id');
        $this->actingAs($owner)->post(route('docuperfect.templates.release', $t->id), ['agency_id' => 99999999])->assertSessionHasErrors('release');
        $this->actingAs($owner)->post(route('docuperfect.templates.release', $t->id), ['agency_id' => $w->id])->assertSessionHasErrors('release');

        $resp = $this->actingAs($owner)->post(route('docuperfect.templates.release', $t->id), ['agency_id' => $client->id]);
        $release = TemplateRelease::withoutGlobalScopes()->first();
        $this->assertNotNull($release);
        $this->trackBlade(Template::withoutGlobalScopes()->find($release->template_id));
        $resp->assertRedirect(route('docuperfect.template-releases.show', $release->id));
        $this->assertSame('awaiting_approval', $release->state);
    }

    public function test_the_workshop_hub_finds_nothing_politely_and_hides_held_back_copies(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $this->megaShaped($w, $owner, ['name' => 'Zebra Lease']);
        $this->releasedCopy($this->megaShaped($w, $owner, ['name' => 'Giraffe Lease']), $client, $owner);

        $this->assertStringContainsString('No documents match', $this->actingAs($owner)->get(route('docuperfect.workshop.index', ['q' => 'Nothing like this']))->getContent());
        $html = $this->actingAs($owner)->get(route('docuperfect.workshop.index', ['q' => 'Giraffe']))->getContent();
        $this->assertSame(1, substr_count($html, 'data-workshop-row='), 'the verified source is listed; the held-back copy is not');
        $this->actingAs($owner)->get(route('docuperfect.template-releases.index', ['view' => 'workshop']))->assertRedirect(route('docuperfect.workshop.index'));
    }

    // ── the owner's list and log ──────────────────────────────────────────────

    /** @return array{0: \App\Models\Agency, 1: \App\Models\Agency, 2: User} */
    private function three(): array
    {
        $w = $this->agency('CoreX Workshop');
        $c1 = $this->agency('Cape Town Lettings');
        $c2 = $this->agency('Durban Rentals');
        $owner = $this->owner();
        $this->releasedCopy($this->megaShaped($w, $owner, ['name' => 'Alpha Lease']), $c1, $owner);
        [$r2] = $this->releasedCopy($this->megaShaped($w, $owner, ['name' => 'Bravo Disclosure']), $c2, $owner);
        $this->svc()->decline($r2, $this->agencyAdmin($c2), 'Wrong clause numbering');

        return [$c1, $c2, $owner];
    }

    public function test_the_releases_list_searches_filters_sorts_and_has_empty_states(): void
    {
        [$c1, $c2, $owner] = $this->three();
        $url = fn (array $q = []) => route('docuperfect.template-releases.index', $q);

        $all = $this->actingAs($owner)->get($url())->assertOk()->getContent();
        $this->assertSame(2, substr_count($all, 'data-release-row='));

        $this->assertSame(1, substr_count($this->actingAs($owner)->get($url(['q' => 'Alpha']))->getContent(), 'data-release-row='), 'search by document name');
        $this->assertSame(1, substr_count($this->actingAs($owner)->get($url(['q' => 'Durban']))->getContent(), 'data-release-row='), 'search by agency name');
        $this->assertSame(1, substr_count($this->actingAs($owner)->get($url(['q' => 'numbering']))->getContent(), 'data-release-row='), 'search by decision note');
        $this->assertSame(2, substr_count($this->actingAs($owner)->get($url(['q' => $owner->name]))->getContent(), 'data-release-row='), 'search by released-by name');

        $this->assertSame(1, substr_count($this->actingAs($owner)->get($url(['state' => 'declined']))->getContent(), 'data-release-row='));
        $this->assertSame(1, substr_count($this->actingAs($owner)->get($url(['agency_id' => $c1->id]))->getContent(), 'data-release-row='));
        $this->assertSame(0, substr_count($this->actingAs($owner)->get($url(['from' => '2099-01-01']))->getContent(), 'data-release-row='), 'released-date range');
        $this->assertSame(2, substr_count($this->actingAs($owner)->get($url(['from' => '2000-01-01', 'to' => '2099-12-31']))->getContent(), 'data-release-row='));

        $asc = $this->actingAs($owner)->get($url(['sort' => 'template', 'dir' => 'asc']))->getContent();
        $this->assertLessThan(strpos($asc, 'Bravo Disclosure'), strpos($asc, 'Alpha Lease'));
        $desc = $this->actingAs($owner)->get($url(['sort' => 'template', 'dir' => 'desc']))->getContent();
        $this->assertLessThan(strpos($desc, 'Alpha Lease'), strpos($desc, 'Bravo Disclosure'));
        $this->actingAs($owner)->get($url(['sort' => 'nonsense; drop table', 'dir' => 'sideways', 'from' => 'not-a-date']))->assertOk();   // absorbed: default sort, bad date ignored

        $this->assertStringContainsString('No releases match these filters', $this->actingAs($owner)->get($url(['q' => 'zzz-nothing']))->getContent());
    }

    public function test_the_releases_list_is_empty_politely_and_paginates(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $this->assertStringContainsString('Nothing has been released yet', $this->actingAs($owner)->get(route('docuperfect.template-releases.index'))->getContent());

        for ($i = 1; $i <= 22; $i++) {
            TemplateRelease::withoutGlobalScopes()->create([
                'source_template_id' => 100000 + $i, 'template_id' => 200000 + $i, 'agency_id' => $client->id, 'version' => 1, 'state' => 'live',
                'released_by' => $owner->id, 'released_at' => now()->subMinutes($i),
            ]);
        }
        $p1 = $this->actingAs($owner)->get(route('docuperfect.template-releases.index'))->getContent();
        $p2 = $this->actingAs($owner)->get(route('docuperfect.template-releases.index', ['page' => 2]))->getContent();
        $this->assertSame(20, substr_count($p1, 'data-release-row='));
        $this->assertSame(2, substr_count($p2, 'data-release-row='));
    }

    public function test_the_release_log_lists_every_step_with_search_filter_and_sort(): void
    {
        [$c1, $c2, $owner] = $this->three();
        $url = fn (array $q = []) => route('docuperfect.template-releases.index', ['view' => 'log'] + $q);

        $html = $this->actingAs($owner)->get($url())->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(5, substr_count($html, 'data-log-row'), 'verified x2, released x2, declined x1');
        $this->assertSame(1, substr_count($this->actingAs($owner)->get($url(['event' => 'declined']))->getContent(), 'data-log-row'));
        $this->assertSame(2, substr_count($this->actingAs($owner)->get($url(['event' => 'released']))->getContent(), 'data-log-row'));
        $this->assertSame(3, substr_count($this->actingAs($owner)->get($url(['q' => 'Bravo']))->getContent(), 'data-log-row'), 'search by template name: verified, released and declined');
        $this->assertSame(1, substr_count($this->actingAs($owner)->get($url(['event' => 'released', 'agency_id' => $c1->id]))->getContent(), 'data-log-row'));
        $this->assertSame(1, substr_count($this->actingAs($owner)->get($url(['q' => 'numbering']))->getContent(), 'data-log-row'), 'search by note');
        $this->actingAs($owner)->get($url(['sort' => 'event', 'dir' => 'asc']))->assertOk();
        $this->assertStringContainsString('No log entries match these filters', $this->actingAs($owner)->get($url(['q' => 'zzz-nothing']))->getContent());
    }

    // ── the agency's approvals ────────────────────────────────────────────────

    public function test_the_approvals_list_is_scoped_to_the_agency_and_defaults_to_waiting(): void
    {
        [$c1, $c2, $owner] = $this->three();
        $a1 = $this->agencyAdmin($c1);
        $a2 = $this->agencyAdmin($c2);

        $h1 = $this->actingAs($a1)->get(route('docuperfect.template-approvals.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($h1, 'data-approval-row='));
        $this->assertStringContainsString('Alpha Lease', $h1);
        $this->assertStringNotContainsString('Bravo Disclosure', $h1, 'another agency\'s release never appears');
        $this->assertStringContainsString('data-authoriser-notice', $h1, 'nobody is named yet');

        $this->assertSame(0, substr_count($this->actingAs($a2)->get(route('docuperfect.template-approvals.index'))->getContent(), 'data-approval-row='), 'agency 2 has only a declined one; the default shows waiting');
        $this->assertSame(1, substr_count($this->actingAs($a2)->get(route('docuperfect.template-approvals.index', ['state' => 'declined']))->getContent(), 'data-approval-row='));
        $this->assertSame(1, substr_count($this->actingAs($a2)->get(route('docuperfect.template-approvals.index', ['state' => 'all']))->getContent(), 'data-approval-row='));
        $this->assertStringContainsString('Nothing is waiting for approval', $this->actingAs($a2)->get(route('docuperfect.template-approvals.index'))->getContent());
        $this->assertStringContainsString('No documents match', $this->actingAs($a1)->get(route('docuperfect.template-approvals.index', ['q' => 'zzz']))->getContent());
    }

    public function test_a_release_of_another_agency_is_a_404_by_direct_url_everywhere(): void
    {
        [$c1, $c2] = $this->three();
        $a1 = $this->agencyAdmin($c1);
        $theirs = TemplateRelease::withoutGlobalScopes()->where('agency_id', $c2->id)->first();

        $this->actingAs($a1)->get(route('docuperfect.template-approvals.show', $theirs->id))->assertNotFound();
        $this->actingAs($a1)->post(route('docuperfect.template-approvals.approve', $theirs->id), ['i_have_looked' => 1])->assertNotFound();
        $this->actingAs($a1)->post(route('docuperfect.template-approvals.decline', $theirs->id), ['reason' => 'nope nope'])->assertNotFound();
        $this->assertSame('declined', $theirs->fresh()->state);
    }

    public function test_approve_and_decline_through_the_routes_and_the_detail_page(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $admin = $this->agencyAdmin($client);
        [$release, $copy] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);

        $detail = $this->actingAs($admin)->get(route('docuperfect.template-approvals.show', $release->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-decision-panel', $detail);
        $this->assertStringContainsString('data-approve-form', $detail);
        $this->assertStringContainsString('data-open-readiness', $detail);

        // No tick -> a plain message, still waiting.
        $this->actingAs($admin)->post(route('docuperfect.template-approvals.approve', $release->id))->assertSessionHasErrors('release');
        $this->assertSame('awaiting_approval', $release->fresh()->state);

        // Decline with a blank reason -> plain message; with a reason -> declined.
        $this->actingAs($admin)->post(route('docuperfect.template-approvals.decline', $release->id), ['reason' => '  '])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('docuperfect.template-approvals.decline', $release->id), ['reason' => 'The deposit clause is wrong'])->assertRedirect(route('docuperfect.template-approvals.show', $release->id));
        $this->assertSame('declined', $release->fresh()->state);
        $this->assertStringContainsString('The deposit clause is wrong', $this->actingAs($owner)->get(route('docuperfect.template-releases.show', $release->id))->getContent());
        $this->assertStringNotContainsString('data-decision-panel', $this->actingAs($admin)->get(route('docuperfect.template-approvals.show', $release->id))->getContent(), 'no buttons once decided');

        // A second release approved through the route goes live.
        $t2 = $this->megaShaped($w, $owner, ['name' => 'Second doc']);
        [$r2, $copy2] = $this->releasedCopy($t2, $client, $owner);
        $this->actingAs($admin)->post(route('docuperfect.template-approvals.approve', $r2->id), ['i_have_looked' => 1, 'note' => 'ok'])->assertRedirect(route('docuperfect.template-approvals.show', $r2->id));
        $this->assertSame('live', $r2->fresh()->state);
        $this->assertSame('live', $copy2->fresh()->release_status);
    }

    public function test_an_authoriser_who_is_not_named_gets_403_on_the_buttons_and_the_owner_sees_read_only(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $admin = $this->agencyAdmin($client);
        $named = $this->agencyUserWithRole($client, 'agent');
        [$release] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);
        app(\App\Services\Docuperfect\Release\AgencyDocumentAuthorisers::class)->save($client->id, [$named->id], null, $admin);

        // The admin is no longer named: the tab and screens close, the POSTs are refused.
        $this->actingAs($admin)->get(route('docuperfect.template-approvals.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('docuperfect.template-approvals.approve', $release->id), ['i_have_looked' => 1])->assertForbidden();
        $this->assertSame('awaiting_approval', $release->fresh()->state);

        // The owner reads every agency's approvals but gets no buttons and cannot approve.
        $html = $this->actingAs($owner)->get(route('docuperfect.template-approvals.show', $release->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-decision-panel', $html);
        $this->assertStringContainsString('data-cannot-decide', $html);
        $this->assertStringContainsString('data-owner-readonly', $this->actingAs($owner)->get(route('docuperfect.template-approvals.index'))->getContent());
        $this->actingAs($owner)->post(route('docuperfect.template-approvals.approve', $release->id), ['i_have_looked' => 1])->assertForbidden();

        // The named person can.
        $this->actingAs($named)->post(route('docuperfect.template-approvals.approve', $release->id), ['i_have_looked' => 1])->assertRedirect();
        $this->assertSame('live', $release->fresh()->state);
    }

    public function test_withdraw_through_the_route_and_the_detail_timeline(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        [$release] = $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);

        $detail = $this->actingAs($owner)->get(route('docuperfect.template-releases.show', $release->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-release-timeline', $detail);
        $this->assertStringContainsString('data-event="released"', $detail);

        $this->actingAs($owner)->post(route('docuperfect.template-releases.withdraw', $release->id), ['note' => 'Wrong agency'])->assertRedirect(route('docuperfect.template-releases.show', $release->id));
        $this->assertSame('withdrawn', $release->fresh()->state);
        $this->assertStringContainsString('data-event="withdrawn"', $this->actingAs($owner)->get(route('docuperfect.template-releases.show', $release->id))->getContent());
        $this->actingAs($owner)->post(route('docuperfect.template-releases.withdraw', $release->id))->assertSessionHasErrors('release');   // a second withdraw is refused politely
    }
}
