<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Models\SystemUpdate;

/**
 * The pop-up as it actually renders — spec §8.2, §9.3, §9.4, §12.
 *
 * Renders through a REAL authenticated page (the What's New archive uses the same
 * layout as every other CoreX screen), so these assertions prove the global
 * layout wiring, not just a partial in isolation.
 */
final class SystemUpdateModalRenderTest extends SystemUpdateTestCase
{
    public function test_the_modal_appears_on_an_ordinary_page_when_something_is_pending(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertSee("What's new in CoreX", false)
            ->assertSee($update->title);
    }

    public function test_the_modal_is_absent_when_nothing_is_pending(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertDontSee("What's new in CoreX", false);
    }

    public function test_the_modal_disappears_once_dismissed(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();
        $this->service()->dismiss($this->agent, [$update->id]);

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertDontSee("What's new in CoreX", false);
    }

    /** Spec §9.3 — the highest-value XSS target in the product. */
    public function test_a_script_tag_in_the_body_renders_as_text_and_never_executes(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish([
            'title' => 'Security check',
            'body'  => 'Watch this: <script>alert("xss")</script> and this: <img src=x onerror=alert(1)>',
        ]);

        $response = $this->actingAs($this->agent)->get(route('corex.whats-new.index'))->assertOk();
        $html     = $response->getContent();

        // The dangerous form is the UNESCAPED tag. Note that the escaped text still
        // contains the literal substring "onerror=alert(1)" — none of those
        // characters need escaping — so the assertion has to be about the tag
        // delimiters, not the payload text.
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html, 'the body must never emit a live script tag');
        $this->assertStringNotContainsString('<img src=x onerror=', $html, 'the body must never emit a live img/event handler');
        $this->assertStringContainsString('&lt;script&gt;', $html, 'it must appear as visible, escaped text');
        $this->assertStringContainsString('&lt;img src=x onerror=', $html, 'the img tag must survive as escaped text');
    }

    /** Spec §9.4 — a row pointing at a file that is no longer on disk. */
    public function test_a_missing_image_file_renders_without_a_broken_img(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish(['image_path' => 'system-updates/this-file-was-deleted.png']);

        $html = $this->actingAs($this->agent)->get(route('corex.whats-new.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('this-file-was-deleted.png', $html);
    }

    public function test_a_deleted_author_renders_as_system(): void
    {
        $update = $this->publish();
        $this->owner->delete();

        $this->assertSame('System', $update->refresh()->authorName());
    }

    /** Spec §9.4 — a stored type no longer in the config must not throw. */
    public function test_an_unknown_type_falls_back_to_a_neutral_chip(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();
        // Bypass validation the way a legacy row or a removed config entry would.
        $update->forceFill(['type' => 'retired_category'])->save();

        $this->assertSame('Update', $update->refresh()->typeLabel());

        $this->actingAs($this->agent)->get(route('corex.whats-new.index'))->assertOk();
    }

    public function test_the_overflow_line_appears_beyond_the_cap(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        for ($i = 0; $i < 7; $i++) {
            $this->publish(['title' => "Release note {$i}", 'published_at' => now()->subMinutes(10 - $i)]);
        }

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertSee('2 more updates');
    }

    public function test_the_type_chip_renders_its_label(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish(['type' => 'fix', 'title' => 'Fixed the duplicate contact merge']);

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertSee('Fixed');
    }

    // ── The archive itself (spec §7.5) ──────────────────────────────────────

    public function test_the_archive_never_lists_an_update_from_before_the_user_joined(): void
    {
        $old = $this->publish(['published_at' => now()->subYear(), 'title' => 'Ancient history']);
        $this->joinedAt($this->agent, now()->subDay());

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertDontSee($old->title);
    }

    public function test_the_archive_still_lists_what_the_user_already_dismissed(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();
        $this->service()->dismiss($this->agent, [$update->id]);

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index'))
            ->assertOk()
            ->assertSee($update->title);
    }

    public function test_the_archive_filters_by_type(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $feature = $this->publish(['type' => 'feature', 'title' => 'A brand new thing']);
        $fix     = $this->publish(['type' => 'fix',     'title' => 'A repaired thing']);

        // Dismiss both first: while they are pending the MODAL also renders them,
        // so the filtered-out title would still appear on the page — via the pop-up,
        // not the archive list.
        $this->service()->dismiss($this->agent, [$feature->id, $fix->id]);

        $this->actingAs($this->agent)
            ->get(route('corex.whats-new.index', ['type' => 'fix']))
            ->assertOk()
            ->assertSee('A repaired thing')
            ->assertDontSee('A brand new thing');
    }
}
