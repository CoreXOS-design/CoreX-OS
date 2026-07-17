<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-231 P2 — a learned correspondence reference. On the first manual verify of
 * a correspondence, the resolving signal is saved here (is_verified=true) so a
 * subsequent email carrying the same signal auto-files to the same deal, silently
 * ("done for the rest of the transaction"). Mirrors pdf_splitter_learned_phrases.
 */
class CommunicationLearnedRef extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'communication_learned_refs';

    // Signal types (what we learned about a correspondence).
    const SIGNAL_CX_TOKEN        = 'cx_token';        // our own "[CX-D{id}]" token
    const SIGNAL_THREAD_KEY      = 'thread_key';      // our outbound Message-ID a reply threaded on
    const SIGNAL_SUBJECT_PATTERN = 'subject_pattern'; // a captured recurring subject pattern
    const SIGNAL_EXTERNAL_REF    = 'external_ref';    // the attorney's own matter ref (difficult route)
    const SIGNAL_SENDER_EMAIL    = 'sender_email';    // fall back: this sender → this deal

    protected $fillable = [
        'agency_id', 'deal_id', 'attorney_provider_id', 'attorney_provider_contact_id',
        'signal_type', 'signal_value', 'is_verified', 'verified_by_user_id', 'verified_at', 'hits',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'hits'        => 'integer',
    ];

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** Normalise a signal value the same way on write and lookup (lowercased/trimmed). */
    public static function normalizeValue(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
