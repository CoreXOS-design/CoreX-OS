<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

/**
 * The modal's CLIENT-SIDE dismissal wiring, asserted on the HTML the browser receives.
 *
 * Spec: .ai/specs/system-updates.md §8, §9.5, §11.2
 *
 * Every other test in this suite proves the SERVER records a dismissal correctly. That
 * was never the failure: the endpoint, the service and the eligibility SQL were all
 * green while users were still being shown the same pop-up on every single page. The
 * dismissal POST is fire-and-forget by design (see the partial's docblock), so anything
 * that stops it leaving the browser fails SILENTLY and presents exactly as "the pop-up
 * is broken" — with nothing in the logs, because the request never arrived.
 *
 * These assertions therefore guard the three ways the request can die in the browser:
 *
 *  1. WRONG ORIGIN — route() builds from APP_URL. On any install whose APP_URL is not
 *     the host being browsed (QA and staging clones habitually share a config), the POST
 *     goes cross-origin: blocked by CORS, no session cookie, dismissal lost, modal back
 *     on the next page. A relative path cannot do this.
 *  2. AN EXIT THAT NEVER DISMISSES — the modal's own links navigate away without
 *     calling close(), so each one must be marked for the init() handler.
 *  3. THE SCRIPT NEVER ARRIVING — the Alpine component is @push'ed to the 'scripts'
 *     stack; if it did not reach the page, x-data would throw and the modal could not
 *     work at all.
 */
final class SystemUpdateDismissWiringTest extends SystemUpdateTestCase
{
    /** @return string the fully-rendered HTML of a real authenticated page showing the modal */
    private function pageWithModal(): string
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish();

        return $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertSee("What's new in CoreX", false)
            ->getContent();
    }

    public function test_the_dismiss_endpoint_is_addressed_relatively_not_via_app_url(): void
    {
        $html = $this->pageWithModal();

        $this->assertMatchesRegularExpression(
            "/x-data=\"coreXSystemUpdates\([^\"]*,\s*'\/api\/v1\/system-updates\/dismiss'\)\"/",
            $html,
            'the modal must POST to a same-origin PATH; an absolute URL breaks dismissal wherever APP_URL != the browsed host',
        );

        $this->assertStringNotContainsString(
            route('api.v1.system-updates.dismiss'),
            $html,
            'the absolute route() URL must not appear — it is the cross-origin failure mode itself',
        );
    }

    public function test_every_link_out_of_the_modal_records_the_dismissal_first(): void
    {
        $html = $this->pageWithModal();

        // Isolate the modal so the surrounding page's links are not judged.
        $start = strpos($html, 'x-data="coreXSystemUpdates');
        $modal = substr($html, $start, strpos($html, "What's new in CoreX") - $start + 4000);

        preg_match_all('/<a\s[^>]*>/s', $modal, $anchors);
        $this->assertNotEmpty($anchors[0], 'the modal is expected to contain at least the "see all" link');

        foreach ($anchors[0] as $anchor) {
            $this->assertStringContainsString(
                'data-system-update-link',
                $anchor,
                "this link leaves the modal without dismissing, so the pop-up returns on arrival: {$anchor}",
            );
        }
    }

    public function test_the_alpine_controller_script_reaches_the_page(): void
    {
        $html = $this->pageWithModal();

        $this->assertStringContainsString(
            'function coreXSystemUpdates',
            $html,
            'the @push\'ed controller must land in the scripts stack, or x-data throws and nothing can be dismissed',
        );

        // keepalive is what lets a dismissal fired on a navigating link outlive the page.
        $this->assertStringContainsString('keepalive: true', $html);
    }

    public function test_the_modal_stays_gone_after_the_endpoint_the_browser_actually_calls(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();

        // Exercise the real HTTP path, not the service, so this covers route + controller
        // + service + persistence together — the whole chain the browser depends on.
        $this->actingAs($this->agent)
            ->postJson('/api/v1/system-updates/dismiss', ['ids' => [$update->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'recorded' => 1]);

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertDontSee("What's new in CoreX", false);
    }
}
