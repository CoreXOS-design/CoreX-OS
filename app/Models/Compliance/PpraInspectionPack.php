<?php

namespace App\Models\Compliance;

use App\Models\Agency;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/ppra-inspection-pack.md §4.5. Table created in Phase F to back
 * the shared sample picker's persistence (§6.8a); the queued generation job
 * and download route are Phase J.
 *
 * agency_id is always set EXPLICITLY at the call site (never relied on via
 * BelongsToAgency's Auth::user() auto-stamp) — see spec header note on the
 * 2026-09-28 whistleblow_email_log.agency_id bug this pattern avoids
 * repeating, since a future queued job (Phase J) runs with no web session.
 */
class PpraInspectionPack extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'ppra_inspection_packs';

    protected $fillable = [
        'agency_id',
        'requested_by_user_id',
        'status',
        'sample_deal_ids',
        'sample_rental_ids',
        'sample_listing_ids',
        'zip_path',
        'report_pdf_path',
        'zip_size_bytes',
        'gaps_summary',
        'error_message',
        'generated_at',
    ];

    protected $casts = [
        'sample_deal_ids'    => 'array',
        'sample_rental_ids'  => 'array',
        'sample_listing_ids' => 'array',
        'gaps_summary'       => 'array',
        'generated_at'       => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** Column that a given picker mode ('deal'|'rental'|'listing') persists its selection onto. */
    public static function sampleColumnForMode(string $mode): string
    {
        return match ($mode) {
            'deal'    => 'sample_deal_ids',
            'rental'  => 'sample_rental_ids',
            'listing' => 'sample_listing_ids',
            default   => abort(422, 'Invalid sample-picker mode.'),
        };
    }

    /**
     * The agency's current DRAFT pack — the one the sample picker reads
     * from and writes to before "Download full inspection pack" (Phase J)
     * is ever triggered. §6.8a: "Selection is per-pack, not a standing
     * setting" — a queued-but-not-yet-generated pack IS that pack; find
     * one or start one, so an admin can open the picker from a checklist
     * row before any generation has ever been requested.
     */
    public static function findOrCreateDraftFor(Agency $agency, User $user): self
    {
        $draft = static::where('agency_id', $agency->id)
            ->where('status', 'queued')
            ->whereNull('generated_at')
            ->latest('created_at')
            ->first();

        if ($draft) {
            return $draft;
        }

        return static::create([
            'agency_id'             => $agency->id,
            'requested_by_user_id'  => $user->id,
            'status'                => 'queued',
        ]);
    }
}
