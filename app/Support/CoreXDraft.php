<?php

namespace App\Support;

use Illuminate\Support\Facades\Session;

/**
 * CoreX Offline Draft Persistence — server-side clear-on-save signal (AT-165).
 *
 * A pure-client draft layer cannot observe a successful submit *after* the
 * browser redirects away from a full-page-POST form. So on a successful
 * store/update the controller flashes the draft key(s) to clear; the layout
 * renders them into <meta name="corex-clear-drafts">; and
 * resources/js/draft-persistence.js wipes those keys on the next page load.
 *
 * This is the ONE deliberate server touch in the draft layer — everything else
 * is 100% client. AJAX forms clear their draft directly and never call this.
 *
 * Usage (in a controller success path):
 *   CoreXDraft::clearOnSave('property_capture', $property->id);
 * On create you typically clear both the "new" draft and the freshly-saved id:
 *   CoreXDraft::clearOnSave('property_capture');            // clears :new
 *   CoreXDraft::clearOnSave('property_capture', $property->id);
 */
class CoreXDraft
{
    public const FLASH_KEY = 'corex_clear_drafts';

    /**
     * Flash a "clear this draft" signal for the next response.
     * $recordId null => the "new" draft; an id => that record's draft.
     */
    public static function clearOnSave(string $form, int|string|null $recordId = null): void
    {
        $rec = $recordId === null ? 'null' : (string) $recordId;
        $signal = $form . ':' . $rec;

        $existing = Session::get(self::FLASH_KEY, []);
        $existing[] = $signal;
        Session::flash(self::FLASH_KEY, array_values(array_unique($existing)));
    }

    /**
     * The signals for this response, as a JSON string for the meta tag.
     * Returns '' when there is nothing to clear (no tag rendered).
     */
    public static function metaContent(): string
    {
        $signals = Session::get(self::FLASH_KEY, []);
        return empty($signals) ? '' : json_encode(array_values($signals));
    }
}
