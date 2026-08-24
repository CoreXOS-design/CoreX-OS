<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\PropertyAdTemplate;

/**
 * Reverts 2026_08_20_000008_backfill_cutout_matte_color_for_removebg_avatars.php
 * (ad-manager.md §15.1 round 6 — REVERTED same day as shipped).
 *
 * Round 6 assumed whatever sits behind an Agent Image element's cutout is a
 * flat-coloured decorative shape, so a matching solid fill would blend in.
 * On Template 1 in production, the shape at that z-order is a photographic
 * background, not a flat colour — the "white card" read as flat only in the
 * synthetic test replica used to prove the mechanism. A solid `#ffffff`
 * rectangle over a photograph is visibly worse than the seam it replaced, at
 * any colour value. This is a wrong fix for a correctly-diagnosed cause, not
 * a tuning problem, so it is reverted rather than re-tuned.
 *
 * Clears `cutoutMatteColor` from every `agent_avatar`/`agent_2_avatar`
 * element that has it set, system-wide — not just Template 1 — undoing the
 * class of change 000008 made (any element it touched, or any a designer
 * set via the now-removed Ad Builder control in the short window round 6
 * was live), not one row picked by hand. 000008 itself is left in place
 * (already ran in production; a migration that has run is not deleted).
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
                if (! array_key_exists('cutoutMatteColor', $el)) {
                    continue;
                }

                unset($layout['elements'][$i]['cutoutMatteColor']);
                $changed = true;
            }

            if ($changed) {
                $tpl->layout_json = $layout;
                $tpl->save();
            }
        });
    }

    public function down(): void
    {
        // Deliberately not reversible: this only ever REMOVES a key, and by
        // the time this runs the value it would restore is already gone.
        // Re-running 000008 is the correct way back, not this migration's
        // down().
    }
};
