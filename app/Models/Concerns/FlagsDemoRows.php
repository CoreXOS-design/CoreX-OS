<?php

namespace App\Models\Concerns;

use App\Support\Instance;

/**
 * On the demo box, every row of the models that carry `is_demo` is saved with is_demo = 1, whatever flow created it.
 *
 * The demo's isolation filters (map layers, MIC, presentation comps, demo:wipe-spatial) all read `is_demo`. Rows made by
 * the seeders set it themselves, but a row made by a screen, an import, the AI report reader or a job on the demo box did
 * not, and showed up as "real" data in a demo (and survived a wipe). This was patched on the demo database with a trigger;
 * the code now does it, so a freshly rebuilt demo database needs no trigger.
 *
 * Gate: App\Support\Instance::isDemo() (COREX_INSTANCE_ROLE=demo), the same predicate the demo gate and the reset
 * countdown use. On any other host the trait does nothing. Tracked properties are deliberately NOT given this trait
 * (Johan's ruling, 28 Oct): the tracked-property layer is shared with real market intelligence.
 *
 * Only Eloquent saves are covered. A raw DB::table()->insert() bypasses model events by design; none of these five tables
 * has one in app code.
 */
trait FlagsDemoRows
{
    protected static function bootFlagsDemoRows(): void
    {
        static::creating(function ($model) {
            if (Instance::isDemo()) {
                $model->setAttribute('is_demo', true);
            }
        });
    }
}
