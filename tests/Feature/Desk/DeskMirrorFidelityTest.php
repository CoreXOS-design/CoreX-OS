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
        foreach (['id="cdc-control" class="corex-btn-primary"', 'class="corex-btn-primary">Join', 'id="cdc-clear" class="corex-btn-outline"', 'id="cdc-end" class="corex-btn-outline"', 'id="cdc-back" class="corex-btn-outline"', 'id="cdc-full" class="corex-btn-outline"'] as $needle) {
            $this->assertStringContainsString($needle, $view);
        }
        foreach (['.corex-btn-primary {', '.corex-btn-outline {'] as $rule) {
            $this->assertStringContainsString($rule, $css, "{$rule} exists in corex.css");
        }
        // display:inline-flex on the button classes beats the hidden attribute unless this rule is there.
        $this->assertStringContainsString('#cdc-root [hidden], #cdc-live [hidden] { display: none !important; }', $view);
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

    public function test_the_mirror_is_fitted_whole_into_the_browser_window_with_letterbox_only_where_the_aspect_differs(): void
    {
        $console = $this->js('console.js');

        // scale = min(window width / agent width, window height / agent height), centred both ways; the stage is the whole viewport.
        $this->assertStringContainsString('Math.min(aw / w, ah / h, 3)', $console);
        $this->assertStringContainsString("rep.style.top = Math.max(0, Math.floor((ah - h * scale) / 2))", $console);
        $this->assertStringContainsString("addEventListener('resize', layoutPane)", $console);
        // The iframe keeps the agent's own viewport size (media queries / sidebar layout are the agent's); only a transform scales it.
        $this->assertStringContainsString("iframe.getAttribute('width')", $console);
        $this->assertStringContainsString("wrapper.style.transform = 'scale('", $console);

        $view = (string) file_get_contents(resource_path('views/desk/console.blade.php'));
        $this->assertStringContainsString('#cdc-live { position: fixed; inset: 0;', $view, 'the viewer is the whole browser window');
        $this->assertStringContainsString('background: #0b0f14', $view, 'dark letterbox');
        $this->assertStringContainsString('#cdc-pane { position: absolute; inset: 0; }', $view);
        $this->assertStringContainsString('#cdc-replay { position: absolute;', $view);
        $this->assertStringNotContainsString('opacity: .', preg_replace('/#cdc-bar[^}]*\}/', '', $view), 'no veil over the mirror');
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
        $this->assertStringContainsString('Sessions open now', $view);                   // on the LIST page (the standard layout), never over the viewer
        // control state is a short chip, not a sentence
        $console = $this->js('console.js');
        $this->assertStringNotContainsString('Click, type and scroll in the window below', $console);
        $this->assertStringContainsString("'Requested'", $console);
        $this->assertStringContainsString("'In control'", $console);
        $this->assertStringContainsString("'Viewing'", $console);
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

    public function test_the_live_session_is_a_full_window_viewer_with_one_auto_hiding_toolbar(): void
    {
        $console = $this->js('console.js');
        $view = (string) file_get_contents(resource_path('views/desk/console.blade.php'));

        // The viewer sits on <body> (nothing of the layout - menu, header, test-site banner - over it) and covers the whole window.
        $this->assertStringContainsString("document.body.appendChild(\$('cdc-live'))", $console);
        $this->assertStringContainsString('z-index: 2000000', $view);
        // One thin toolbar: slides away after 3 s and comes back at the top edge.
        $this->assertStringContainsString('}, 3000);', $console);
        $this->assertStringContainsString('cdc-away', $console);
        $this->assertStringContainsString('<div id="cdc-hot"></div>', $view);
        // Everything the ruling lists is in it, in one place.
        foreach (['id="cdc-agent"', 'id="cdc-support"', 'id="cdc-page"', 'id="cdc-elapsed"', 'id="cdc-control-status"', 'id="cdc-control"', 'id="cdc-hl"', 'id="cdc-note"', 'id="cdc-tabs"', 'id="cdc-full"', 'id="cdc-end"'] as $id) {
            $this->assertStringContainsString($id, $view, "the toolbar has {$id}");
        }
        // The old chrome is gone from the viewer: no list, no technical line.
        $this->assertStringNotContainsString('Highlighted: [', $console);
        $this->assertStringContainsString("'Highlighted: ' + visibleLabel(el)", $console, 'a toast with what it is in words, not a selector');
        $this->assertStringNotContainsString("'Highlighted: ' + selector", $console);
        // Full screen is one click; Esc leaves it and stays in the viewer (the browser does that for us - we only follow fullscreenchange).
        $this->assertStringContainsString('requestFullscreen()', $console);
        $this->assertStringContainsString("addEventListener('fullscreenchange'", $console);
        // Back and End both return to the list page; Back keeps the session open.
        $this->assertStringContainsString('function leave()', $console);
        $this->assertStringContainsString('Open it again from the list', $console);
        // The supporter's own menu is not collapsed any more - the viewer covers it.
        $this->assertStringNotContainsString('collapseMenu', $console);
    }

    public function test_a_click_is_converted_through_the_scale_and_the_iframe_offset_and_checked_against_the_agents_screen(): void
    {
        $console = $this->js('console.js');

        // (clientX - on-screen left of the mirror iframe) / scale, in the agent's own CSS pixels - never the mirror document's clientWidth.
        $this->assertStringContainsString('px: (ev.clientX - r.left) / k', $console);
        $this->assertStringContainsString('const k = r.width / W;', $console);
        $this->assertStringContainsString('d.elementFromPoint(g.px, g.py)', $console);
        $this->assertStringNotContainsString('documentElement.clientWidth', $console);
        // A click names the exact element (not the surrounding [data-tour] section) and carries its box for the agent to compare.
        $this->assertStringContainsString('controlClick(tab, el, exactSelector(el), r)', $console);
        $this->assertStringContainsString('b: boxOf(el)', $console);
        $this->assertStringContainsString('export function exactSelector', $console);
        $this->assertStringContainsString("listenForWhisper('action_result'", $console);
        $this->assertStringContainsString("whisper('action_result'", $this->js('agent.js'));
        $this->assertStringContainsString('client-action_result', (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-ext.js')));
    }

    public function test_the_agents_window_size_is_announced_on_join_resize_and_zoom_and_the_pointer_arrives_in_full(): void
    {
        $agent = $this->js('agent.js');
        $console = $this->js('console.js');
        $ext = (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-content.js'));

        // the agent announces its own current size...
        $this->assertStringContainsString("whisper('viewport', { t: 'main', w: window.innerWidth, h: window.innerHeight", $agent);
        $this->assertStringContainsString("window.addEventListener('resize', onViewportChange)", $agent);
        $this->assertStringContainsString("window.visualViewport.addEventListener('resize', onViewportChange)", $agent);
        $this->assertStringContainsString('emitViewport();', $agent);
        $this->assertStringContainsString("type: 'desk.viewport'", $ext);
        $this->assertStringContainsString("window.addEventListener('resize', onViewport)", $ext);
        $this->assertStringContainsString('client-viewport', (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-ext.js')));
        // ...the console lays the mirror out at it and refits on anything that changes the mirror's size
        $this->assertStringContainsString("listenForWhisper('viewport'", $console);
        $this->assertStringContainsString("f.setAttribute('width', String(v.w))", $console);
        $this->assertStringContainsString('new ResizeObserver(() => { if (cur && cur.selected === tab.key) fit(); })', $console);
        // positions are fractions of the agent's viewport, never absolute pixels; and a stale size is noticed from the pointer
        $this->assertStringContainsString('const ratio = (ev) => { const g = geo(ev); return { x: g.px / g.W, y: g.py / g.H }; };', $console);
        $this->assertStringContainsString('vw: iframe.offsetWidth, vh: iframe.offsetHeight', $console);
        $this->assertStringContainsString('Math.abs(p.vw - window.innerWidth) > 2', $agent);
        // the last pointer position is always sent (trailing throttle)
        $this->assertStringContainsString('trailingTimer = setTimeout', $console);
        $this->assertStringContainsString('a trailing send', $console);
    }

    public function test_events_are_batched_for_60_ms_not_500(): void
    {
        $this->assertStringContainsString('const FLUSH_MS = 60;', $this->js('agent.js'));
        $this->assertStringNotContainsString('const FLUSH_MS = 500;', $this->js('agent.js'));
        $ext = (string) file_get_contents(public_path('chrome-extension/portal-capture/desk-content.js'));
        $this->assertStringContainsString('setTimeout(flush, 60)', $ext);
        $this->assertStringNotContainsString('setTimeout(flush, 500)', $ext);
        $this->assertStringContainsString('scroll: 50', $this->js('agent.js'));
    }

    public function test_the_wrapper_that_takes_the_mouse_is_the_agents_full_window_size_so_the_pointer_reaches_the_whole_picture(): void
    {
        $console = $this->js('console.js');
        // Without an explicit size the block wrapper is only as wide as its (scaled) parent and the transform shrinks it again: hit area = scale x scale.
        $this->assertStringContainsString("wrapper.style.width = w + 'px';", $console);
        $this->assertStringContainsString("wrapper.style.height = h + 'px';", $console);
        $this->assertGreaterThan(
            strpos($console, "wrapper.style.height = h + 'px';"),
            strpos($console, "wrapper.style.transform = 'scale('"),
            'the size is set before the scale is applied'
        );
    }
}

