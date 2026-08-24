<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\PropertyAdTemplate;

/**
 * Ad Manager "Remove background" — one-time data correction (ad-manager.md
 * §15.1 round 6), NOT a template-wide/agency-wide behaviour change.
 *
 * Root cause (proven, not assumed — see the round-6 investigation): an Agent
 * Image element with `removeBackground:true` renders NO fill of its own
 * behind the cutout (frameStyle() never painted one for this field type
 * before round 6). Where such an element is positioned so it overlaps MORE
 * THAN ONE decorative background shape in its own template, a transparent
 * (correctly-removed) pixel reveals whichever shape happens to be
 * underneath at that exact position — different colours on each side of
 * a shape boundary — reading as a hard crop/mask edge that has nothing to
 * do with the removal algorithm itself.
 *
 * This is NOT a general per-template autofix (visually verifying "does this
 * element overlap more than one background colour" for an ARBITRARY
 * template isn't something a migration can safely infer) — it targets
 * EVERY currently-existing element with `removeBackground:true` that has
 * no `cutoutMatteColor` set (confirmed via Tinker: at the time this shipped,
 * that is Template 1's `agent_avatar` element, and NO other template in the
 * system has `removeBackground` enabled anywhere — this migration is
 * therefore the complete, exhaustive fix for every current usage, not a
 * special case for one template picked by hand).
 *
 * For each one found, the matte colour is DERIVED from the template's own
 * data — the background (`bg`) of whichever OVERLAPPING sibling `shape`
 * element has the HIGHEST z-index below the avatar's own (i.e. whichever
 * shape is actually PAINTED ON TOP at that position, matching real DOM/paint
 * order) — never a hardcoded guess, and NOT simply the largest overlap area:
 * a full-bleed background shape almost always has the biggest bounding-box
 * overlap by construction (it covers the whole canvas) while sitting BEHIND
 * a smaller foreground card that is what's actually visible — tested
 * against a replica of the real case and confirmed area-based selection
 * picks the wrong (background) shape; z-index-based selection picks the
 * right (foreground card) one. Idempotent: skips any element that already
 * has `cutoutMatteColor` set (a designer's deliberate choice, made after
 * round 6 shipped, is never overwritten).
 */
return new class extends Migration
{
    public function up(): void
    {
        PropertyAdTemplate::withoutGlobalScopes()->get()->each(function (PropertyAdTemplate $tpl) {
            $layout = $tpl->layout_json;
            if (! is_array($layout['elements'] ?? null)) {
                return;
            }

            $changed = false;
            foreach ($layout['elements'] as $i => $el) {
                $field = $el['field'] ?? null;
                if (! in_array($field, ['agent_avatar', 'agent_2_avatar'], true)) {
                    continue;
                }
                if (empty($el['removeBackground']) || ! empty($el['cutoutMatteColor'])) {
                    continue; // not a cutout, or already deliberately configured — leave alone
                }

                $elZIndex = $el['zIndex'] ?? 1;
                $bestBg = null;
                $bestZIndex = -PHP_INT_MAX;
                foreach ($layout['elements'] as $sibling) {
                    if (($sibling['field'] ?? null) !== 'shape' || empty($sibling['bg'])) {
                        continue;
                    }
                    $siblingZIndex = $sibling['zIndex'] ?? 1;
                    if ($siblingZIndex >= $elZIndex) {
                        continue; // painted ON TOP of the avatar, not behind it — irrelevant to what a transparent pixel reveals
                    }
                    $overlapW = min($el['x'] + $el['w'], $sibling['x'] + $sibling['w']) - max($el['x'], $sibling['x']);
                    $overlapH = min($el['y'] + $el['h'], $sibling['y'] + $sibling['h']) - max($el['y'], $sibling['y']);
                    if ($overlapW <= 0 || $overlapH <= 0) {
                        continue; // no overlap at all
                    }
                    if ($siblingZIndex > $bestZIndex) {
                        $bestZIndex = $siblingZIndex;
                        $bestBg = $sibling['bg'];
                    }
                }

                if ($bestBg !== null) {
                    $layout['elements'][$i]['cutoutMatteColor'] = $bestBg;
                    $changed = true;
                }
            }

            if ($changed) {
                $tpl->layout_json = $layout;
                $tpl->save();
            }
        });
    }

    public function down(): void
    {
        // Deliberately not reversible: this only ever ADDS a value to an
        // element that had none, derived from the template's own existing
        // data — there is nothing distinguishable to roll back to, and
        // reverting would silently reintroduce the round-6 defect for
        // anyone who has since re-saved the template.
    }
};
