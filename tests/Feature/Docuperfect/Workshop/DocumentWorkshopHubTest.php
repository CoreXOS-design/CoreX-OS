<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-transfer.md §13.4a — the Document workshop hub (owner) and the "Approve documents" menu item (agency authorisers):
 * who sees them, what each row shows and offers at each stage, search / filter / sort / empty state, and the links to Releases and Approvals.
 */
final class DocumentWorkshopHubTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    public function test_only_the_corex_owner_opens_the_hub_and_only_the_owner_sees_the_menu_item(): void
    {
        $client = $this->agency('Cape Town Lettings');
        // The Documents group sits inside the agent section of the menu, so the test users hold that key as every real agency role does.
        $admin = $this->agencyManager($client, ['templates.approve_release', 'esign.settings', 'sidebar.section.agents'], 'admin');
        $owner = $this->owner();

        $this->actingAs($admin)->get(route('docuperfect.workshop.index'))->assertForbidden();
        $html = $this->actingAs($owner)->get(route('docuperfect.workshop.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-hub', $html);
        $this->assertStringContainsString('href="' . route('docuperfect.workshop.index') . '" class="corex-nav-subitem', $html, 'the owner has the menu item under Documents');
        $this->assertStringNotContainsString('Approve documents', $html, 'the owner does not approve; that item is the agency side');

        $adminHtml = $this->actingAs($admin)->get(route('docuperfect.template-approvals.index'))->assertOk()->getContent();
        $this->assertStringContainsString('class="corex-nav-subitem', $adminHtml, 'the admin menu really rendered the Documents group');
        $this->assertStringNotContainsString('href="' . route('docuperfect.workshop.index') . '" class="corex-nav-subitem', $adminHtml);
    }

    public function test_the_hub_explains_the_road_and_links_to_releases_and_approvals(): void
    {
        $owner = $this->owner();
        $this->dropAnchor();
        $html = $this->actingAs($owner)->get(route('docuperfect.workshop.index'))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, '<li>Build the document') + substr_count($html, '<li>When it looks right') + substr_count($html, '<li><strong>Release</strong>'));
        $this->assertStringContainsString('href="' . route('docuperfect.template-releases.index') . '"', $html);
        $this->assertStringContainsString('href="' . route('docuperfect.template-approvals.index') . '"', $html);
        $this->assertStringContainsString('There are no templates to prepare yet', $html, 'a real empty state');
    }

    public function test_each_row_shows_its_stage_readiness_and_the_next_action(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $fresh = $this->megaShaped($w, $owner, ['name' => 'Alpha Lease']);
        $verified = $this->megaShaped($w, $owner, ['name' => 'Bravo Lease']);
        $sent = $this->megaShaped($w, $owner, ['name' => 'Charlie Lease']);
        app(\App\Services\Docuperfect\Release\TemplateReleaseService::class)->verify($verified, $owner, true);
        [, $copy] = $this->releasedCopy($sent, $client, $owner);

        $html = $this->actingAs($owner)->get(route('docuperfect.workshop.index'))->assertOk()->getContent();
        $row = fn (int $id) => $this->rowHtml($html, $id);

        $this->assertStringContainsString('data-stage="workshop"', $row($fresh->id));
        $this->assertStringContainsString('data-verify-form', $row($fresh->id), 'not verified: the next step is Verify');
        $this->assertStringNotContainsString('data-release-form', $row($fresh->id));
        $this->assertStringContainsString('Never released', $row($fresh->id));

        $this->assertStringContainsString('data-stage="verified"', $row($verified->id));
        $this->assertStringContainsString('data-release-form', $row($verified->id), 'verified: the next step is Release');
        $this->assertStringContainsString('Check readiness', $row($verified->id));
        $this->assertStringContainsString('Demo view', $row($verified->id));
        $this->assertStringContainsString(route('docuperfect.templates.readiness', $verified->id), $row($verified->id));
        $this->assertStringContainsString(route('docuperfect.templates.demo', $verified->id), $row($verified->id));

        $this->assertStringContainsString('data-stage="released"', $row($sent->id));
        $this->assertStringContainsString('Waiting for approval', $row($sent->id));
        $this->assertStringContainsString('to Cape Town Lettings', $row($sent->id), 'last release shows version and agency');

        $listed = $this->names($html);
        $this->assertContains($fresh->id, $listed);
        $this->assertNotContains((int) $copy->id, $listed, 'the released copy at the agency is not listed as a workshop document');
        $this->assertStringContainsString('1 waiting', $html, 'the Approvals link carries the waiting count');
    }

    public function test_a_changed_verified_document_asks_to_be_verified_again(): void
    {
        $w = $this->agency('CoreX Workshop');
        $owner = $this->owner();
        $t = $this->megaShaped($w, $owner, ['name' => 'Delta Lease']);
        app(\App\Services\Docuperfect\Release\TemplateReleaseService::class)->verify($t, $owner, true);
        $cds = $t->fresh()->cds_json;
        $cds['title'] = 'Changed after verification';
        $t->update(['cds_json' => $cds]);

        $row = $this->rowHtml($this->actingAs($owner)->get(route('docuperfect.workshop.index'))->getContent(), $t->id);
        $this->assertStringContainsString('data-verified-state="stale"', $row);
        $this->assertStringContainsString('Changed since verified', $row);
        $this->assertStringContainsString('data-verify-form', $row);
    }

    public function test_search_filter_and_sort_with_a_filtered_empty_state(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $b = $this->megaShaped($w, $owner, ['name' => 'Bravo Lease']);
        $a = $this->megaShaped($client, $owner, ['name' => 'Alpha Lease']);
        app(\App\Services\Docuperfect\Release\TemplateReleaseService::class)->verify($b, $owner, true);
        $this->dropAnchor();

        $names = fn (string $qs) => $this->names($this->actingAs($owner)->get(route('docuperfect.workshop.index') . $qs)->assertOk()->getContent());

        $this->assertSame([$a->id, $b->id], $names(''), 'default sort = name A-Z');
        $this->assertSame([$b->id, $a->id], $names('?sort=name&dir=desc'));
        $this->assertSame([$b->id], $names('?q=Bravo'));
        $this->assertSame([$a->id], $names('?q=Cape'), 'search matches the agency name too');
        $this->assertSame([$b->id], $names('?stage=verified'));
        $this->assertSame([$a->id], $names('?stage=not_verified'));
        $this->assertSame([$a->id], $names('?agency_id=' . $client->id));
        $this->assertSame([], $names('?stage=live'));
        $this->assertStringContainsString('No documents match these filters', $this->actingAs($owner)->get(route('docuperfect.workshop.index', ['stage' => 'live']))->getContent());
        $this->assertSame([$a->id, $b->id], $names('?sort=nonsense&stage=bogus'), 'unknown sort and stage are ignored, not an error');
    }

    public function test_the_approve_documents_item_is_for_named_authorisers_and_shows_the_waiting_count(): void
    {
        $w = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $admin = $this->agencyManager($client, ['templates.approve_release', 'esign.settings', 'sidebar.section.agents'], 'admin');
        $agent = $this->agencyUserWithRole($client, 'agent', ['manage_templates', 'sidebar.section.agents']);   // not an authoriser

        $before = $this->actingAs($admin)->get(route('docuperfect.template-approvals.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Approve documents', $before);
        $this->assertStringNotContainsString('data-approve-waiting', $before, 'nothing waiting, no count');

        $this->releasedCopy($this->megaShaped($w, $owner), $client, $owner);
        $after = $this->actingAs($admin)->get(route('docuperfect.template-approvals.index'))->getContent();
        $this->assertMatchesRegularExpression('/data-approve-waiting>\s*1\s*</', $after);

        $agentHtml = $this->actingAs($agent)->get(route('docuperfect.templates.index'))->getContent();
        $this->assertStringNotContainsString('Approve documents', $agentHtml, 'a manager who is not named an authoriser has no such item');
    }

    /** The fixtures park an "id anchor" template at id 900000; tests that count rows remove it first. */
    private function dropAnchor(): void
    {
        \DB::table('docuperfect_templates')->where('id', 900000)->delete();
    }

    /** The <tr> of one row. */
    private function rowHtml(string $html, int $id): string
    {
        $this->assertSame(1, preg_match('/<tr[^>]*data-workshop-row="' . $id . '".*?<\/tr>/s', $html, $m), 'row for template ' . $id . ' is listed');

        return $m[0];
    }

    /** @return list<int> template ids in the order listed */
    private function names(string $html): array
    {
        preg_match_all('/data-workshop-row="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }
}
