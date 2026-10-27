<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use Tests\TestCase;

/**
 * CoreX Desk - the mirror must look like the real page, and the console must look like a console (spec .ai/specs/corex-desk.md section 4a).
 * Found in Johan's real-Chrome test on QA1: menus and cards missing from the mirror, a dark strip where the agent's red bar is, and console
 * buttons that did not look like buttons. The browser proof compares menu counts and computed colours real vs mirror; these pin the causes.
 */
final class DeskMirrorFidelityTest extends TestCase
{
    private function js(string $f): string
    {
        return (string) file_get_contents(resource_path('js/desk/' . $f));
    }

    public function test_the_replayer_does_not_freeze_css_animations_and_blocked_boxes_keep_their_room_but_not_a_dark_colour(): void
    {
        $console = $this->js('console.js');

        // rrweb's live mode otherwise adds html.rrweb-paused and pauses EVERY animation: menus / cards that fade in stay invisible.
        $this->assertStringContainsString('pauseAnimation: false', $console);
        // rrweb paints a blocked (.rr-block) element as a solid currentColor box - the dark strip where the agent's Desk bar is.
        $this->assertStringContainsString(".rr-block { background: transparent !important; }", $console);
        $this->assertStringContainsString('insertStyleRules', $console);
        $this->assertStringContainsString('useVirtualDom: false', $console, 'the earlier live-mode fix stays');
    }

    public function test_both_recorders_refresh_the_snapshot_so_script_set_state_cannot_drift_and_a_busy_page_is_complete(): void
    {
        foreach ([resource_path('js/desk/agent.js'), public_path('chrome-extension/portal-capture/desk-content.js')] as $file) {
            $src = (string) file_get_contents($file);
            $this->assertStringContainsString('checkoutEveryNms: 45000', $src, basename($file));
            $this->assertMatchesRegularExpression('/setTimeout\(.*resync\(\).*2500\)/', $src, basename($file) . ' takes a second snapshot once the page has drawn itself');
        }
    }

    public function test_the_console_buttons_use_classes_that_have_css_and_hidden_still_hides(): void
    {
        $view = (string) file_get_contents(resource_path('views/desk/console.blade.php'));
        $css = (string) file_get_contents(resource_path('css/corex.css'));

        $this->assertStringNotContainsString('ds-btn', $view, 'ds-btn has no CSS anywhere - those buttons rendered as plain text');
        $this->assertStringNotContainsString('ds-btn', $this->js('console.js'));
        foreach (['id="cdc-control" class="corex-btn-primary"', 'class="corex-btn-primary">Join', 'id="cdc-clear" class="corex-btn-outline"', 'id="cdc-end" class="corex-btn-outline"'] as $needle) {
            $this->assertStringContainsString($needle, $view);
        }
        foreach (['.corex-btn-primary {', '.corex-btn-outline {'] as $rule) {
            $this->assertStringContainsString($rule, $css, "{$rule} exists in corex.css");
        }
        // display:inline-flex on the button classes beats the hidden attribute unless this rule is there.
        $this->assertStringContainsString('#cdc-root [hidden] { display: none !important; }', $view);
    }

    public function test_an_agent_page_that_cannot_ask_is_noticed_by_the_console(): void
    {
        $console = $this->js('console.js');
        $this->assertStringContainsString("listenForWhisper('control_ack'", $console);
        $this->assertStringContainsString('has not shown the question', $console);
        $this->assertStringContainsString('askTimer', $console);

        $this->assertStringContainsString("whisper('control_ack'", $this->js('agent.js'), 'the CoreX page acknowledges the question');
        $this->assertStringContainsString('client-control_ack', (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-ext.js')), 'so does a shared extension tab');
    }

    public function test_the_spec_records_the_mirror_fidelity_rules(): void
    {
        $spec = (string) file_get_contents(base_path('.ai/specs/corex-desk.md'));
        foreach (['pauseAnimation', 'rrweb-paused', 'control_ack', 'checkoutEveryNms', 'corex-btn-primary'] as $term) {
            $this->assertStringContainsString($term, $spec, "the spec must mention {$term}");
        }
    }

    public function test_the_mirror_is_fitted_whole_into_a_pane_whose_size_comes_from_the_window_never_from_its_content(): void
    {
        $console = $this->js('console.js');

        // scale = min(pane width / agent width, pane height / agent height); never above 1.
        $this->assertStringContainsString('Math.min(1, aw / w, ah / h)', $console);
        // The pane's height comes from the window (what is left under the header / bar / list) and the mirror is an absolutely positioned child,
        // so the agent's 1440 px page cannot stretch the console or add scrollbars.
        $this->assertStringContainsString('window.innerHeight - top - below', $console);
        $this->assertStringContainsString('new ResizeObserver', $console);
        $this->assertStringContainsString("addEventListener('resize', layoutPane)", $console);
        // The iframe keeps the agent's own viewport size (media queries / sidebar layout are the agent's); only a transform scales it.
        $this->assertStringContainsString("iframe.getAttribute('width')", $console);
        $this->assertStringContainsString("wrapper.style.transform = 'scale('", $console);

        $view = (string) file_get_contents(resource_path('views/desk/console.blade.php'));
        $this->assertStringContainsString('#cdc-pane { position: relative; overflow: hidden;', $view);
        $this->assertStringContainsString('#cdc-replay { position: absolute;', $view);
    }

    public function test_the_console_is_a_standard_page_with_no_helper_paragraphs(): void
    {
        $view = (string) file_get_contents(resource_path('views/desk/console.blade.php'));

        $this->assertStringContainsString("@extends('layouts.corex')", $view, 'the standard layout: left menu + header');
        $this->assertStringContainsString('<x-page-header title="CoreX Desk">', $view, 'the sticky page header');
        foreach (['See an agent', 'Move your mouse', 'Ask ', 'The agent reads you', 'Click anything to highlight', 'corex-page-banner'] as $helper) {
            $this->assertStringNotContainsString($helper, $view, "no helper text: {$helper}");
        }
        $this->assertStringContainsString('id="cdc-form"', $view);                       // code + Join on one line, in the header
        $this->assertStringContainsString('#cdc-bar { position: sticky;', $view);          // the session bar stays put
        $this->assertStringContainsString('Sessions open now', $view);
        // control state is a short chip, not a sentence
        $console = $this->js('console.js');
        $this->assertStringNotContainsString('Click, type and scroll in the window below', $console);
        $this->assertStringContainsString("'Waiting for Allow…'", $console);
    }

    public function test_sub_menus_and_other_script_revealed_content_mirror_because_animations_run(): void
    {
        // The sidebar's slide-in sub-menu (Real Estate, Dashboard ...) and the Properties cards appear through entrance animations
        // (`animation ... backwards`: opacity 0 at the start). A frozen animation = an empty list under "< Back / REAL ESTATE".
        $this->assertStringContainsString('pauseAnimation: false', $this->js('console.js'));
        $css = (string) file_get_contents(resource_path('views/corex/properties/index.blade.php'));
        $this->assertStringContainsString('animation: props-card-rise', $css, 'the kind of animation that was being frozen');
    }

    public function test_the_mirror_does_not_replay_entrance_animations_and_selects_keep_their_choice(): void
    {
        $console = $this->js('console.js');
        $this->assertStringContainsString('animation-duration: 0.001s !important', $console);
        $this->assertStringContainsString('transition-duration: 0s !important', $console);

        // a masked <select> loses option.selected in rrweb - so selects are not in the mask list (either recorder)
        foreach ([$this->js('agent.js'), (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-content.js'))] as $src) {
            $this->assertDoesNotMatchRegularExpression('/textarea: true, select: true/', $src);
        }
    }

    public function test_the_supporters_own_menu_collapses_while_a_session_is_open_using_the_layouts_existing_mechanism(): void
    {
        $console = $this->js('console.js');
        $this->assertStringContainsString('function collapseMenu(on)', $console);
        $this->assertStringContainsString("'rental-markup-view-toggled'", $console, 'the layout\'s own collapse event');
        $this->assertStringContainsString('markupSidebarExpanded = false', $console);
        $this->assertSame(1, substr_count($console, 'collapseMenu(true);'), 'collapsed when a session is attached');
        $this->assertGreaterThanOrEqual(1, substr_count($console, 'collapseMenu(false);'), 'restored when it ends / is left');
        // the mechanism really is in the layout (so reusing it is not a guess)
        $layout = (string) file_get_contents(resource_path('views/layouts/corex.blade.php'));
        $this->assertStringContainsString('@rental-markup-view-toggled.window="markupModeActive = $event.detail.open"', $layout);
        $this->assertStringContainsString("markupSidebarExpanded ? 'Collapse menu' : 'Expand menu'", $layout, 'with its own toggle to bring the menu back');
        $this->assertStringContainsString("['cdc-live', 'cdc-bar', 'cdc-tabs', 'cdc-sessions']", $console, 'the mirror is refitted when the menu collapses');
    }
}

