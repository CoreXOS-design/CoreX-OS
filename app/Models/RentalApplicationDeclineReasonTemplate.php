<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Johan, 2026-09-15: "each with two parts — the reason itself... and the
 * guidance that goes with it... a decline that tells an applicant how to
 * fix it is something no other CRM does." Full CRUD, agency-owned,
 * archive-not-delete — same shape as RentalApplicationHighlighter, not a
 * new pattern.
 *
 * Boundary agreed with cc5 before either lane wrote code: this model is
 * the reason+guidance LIBRARY only. cc5 owns the decline modal's picker,
 * the send step, and the merge into the EXISTING decline email envelope
 * (RentalApplicationDeclineEmailSetting::render(), extended with
 * {{decline_reason}}/{{decline_guidance}} placeholders sourced from
 * whichever row here the authoriser picked) — one wording system, this
 * table is pure data it reads from, never a second envelope next to it.
 */
class RentalApplicationDeclineReasonTemplate extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = ['agency_id', 'reason', 'guidance', 'sort_order', 'created_by'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /** Nullable and never backfilled for seeded rows — same honest-absence convention as RentalApplicationHighlighter::creator(). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The two starting templates — Johan: "firm about the reason,
     * genuinely helpful about the remedy." GENERAL TIPS ONLY: never a
     * number (a rand figure, a percentage, a score) and never a sentence
     * of the shape "do this and you will be approved." These seed every
     * agency's own wording, so they must MODEL that line, not test it —
     * whoever edits this constant keeps that rule, not just this file's
     * own spec section.
     */
    public const DEFAULT_SEED = [
        [
            'reason' => 'Affordability',
            'guidance' => "The application didn't meet our affordability guideline this time. General tips that help going forward: keep your monthly debt repayments well below your income, avoid taking on new credit shortly before applying, and where possible show a consistent income history on your bank statements over the full period requested.",
        ],
        [
            'reason' => 'Unpaid debit orders on bank statements',
            'guidance' => "Your bank statements showed debit orders that didn't go through. General tips that help going forward: for a minimum period of three months, make sure every scheduled debit order is paid in full and on time, and keep enough available balance in the days around your usual debit order dates.",
        ],
    ];

    /**
     * Seeds the two starting templates for one agency. Shared by the
     * one-time backfill migration (existing agencies) and
     * SeedDefaultRentalApplicationDeclineReasonTemplates (new agencies, via
     * the existing AgencyCreated domain event) — one seeding function, two
     * callers, so they can never drift apart. Idempotent: does nothing if
     * this agency already has any row (seeded or hand-created).
     */
    public static function seedDefaultsFor(int $agencyId): void
    {
        if (static::withTrashed()->where('agency_id', $agencyId)->exists()) {
            return;
        }

        $rows = [];
        foreach (self::DEFAULT_SEED as $order => $seed) {
            $rows[] = [
                'agency_id' => $agencyId,
                'reason' => $seed['reason'],
                'guidance' => $seed['guidance'],
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Bulk insert() bypasses Eloquent's creating() hooks entirely
        // (including BelongsToAgency's auto-stamp) — agency_id is already
        // explicit on every row above.
        static::insert($rows);
    }

    /**
     * cc5's own read contract, agreed directly before either lane wrote
     * code: non-archived rows for one agency, ordered by sort_order then
     * id, for the decline modal's picker.
     */
    public static function activeFor(int $agencyId): \Illuminate\Support\Collection
    {
        return static::where('agency_id', $agencyId)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }
}
