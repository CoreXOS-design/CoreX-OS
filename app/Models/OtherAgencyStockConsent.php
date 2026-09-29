<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only evidence that an agent confirmed they have the source
 * agency's permission to use its portal advert, before importing it as
 * Other Agency Stock. .ai/specs/other-agency-stock.md §3a.
 *
 * NEVER updated, NEVER deleted (Johan's requirement — a re-import records a
 * NEW row, always). update()/delete() are overridden below to make that a
 * real guarantee, not just a convention nobody happens to violate yet.
 */
class OtherAgencyStockConsent extends Model
{
    use BelongsToAgency;

    /** The default wording shown when an agency hasn't customised its own (Settings). Johan, 2026-09-29. */
    public const DEFAULT_WORDING = 'I confirm that I have received permission from this agency to use their portal advert. '
        . 'I understand this listing is imported for sharing with my buyers only (viewings and viewing packs) and will not '
        . 'be advertised, syndicated or published by me or my agency.';

    /** Bumped only if the consent FLOW itself changes (new required fields, etc.) — not on agency wording edits, which are captured verbatim per row. */
    public const WORDING_VERSION = 1;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id', 'property_id', 'user_id',
        'consented_at', 'consent_wording', 'consent_wording_version',
        'portal', 'listing_ref', 'listing_url', 'source_agency_name',
        'ip_address', 'user_agent',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @throws \LogicException always — this table is append-only. */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException('OtherAgencyStockConsent rows are append-only and can never be updated.');
    }

    /** @throws \LogicException always — this table is append-only. */
    public function delete()
    {
        throw new \LogicException('OtherAgencyStockConsent rows are append-only and can never be deleted.');
    }
}
