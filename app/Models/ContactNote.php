<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;

/**
 * A note NEVER moves the contact's Last Contacted marker, from any screen, the API, an
 * import or a system-generated note (Johan, 2026-10-07: "Only 'Contacted and note' moves
 * it. 'Note only' could be anything and does not mean the contact was contacted").
 * The one place the marker moves for a note is the explicit "Contacted and note" action:
 * ContactNoteController::store() → Contact::markContacted(). Do not add a created-hook here.
 */
class ContactNote extends Model
{
    use BelongsToAgency, SoftDeletes;

    /**
     * Buyer pipeline quick-pick note types (Johan, 2026-08-20 — "dropdown
     * quick picks and free text"). ONE place, a one-line edit to change —
     * not hardcoded in a Blade file. The stored value IS the label (no
     * separate key/label mapping) so the round-trip between the buyer
     * pipeline and the contact record needs no translation layer.
     *
     * Enforced at the application layer (ContactNoteController::store()),
     * not a DB enum — see the migration's own comment for why. Adding a
     * type here never needs a migration; only lengthening past 40 chars
     * would.
     */
    public const QUICK_PICK_TYPES = [
        'Contacted',
        'Viewing booked',
        'Viewing done',
        'Offer discussed',
        'Not interested',
        'Follow up later',
    ];

    protected $fillable = [
        'agency_id', 'contact_id', 'user_id', 'type', 'body'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
