<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-401 — one row per highlight or note drawn on a rental-application
 * document. Replaces marks_json (a single JSON blob per document) so
 * ownership can be enforced PER MARK, at the database/service layer, not by
 * trusting a save's merge logic to be correct every time. See the creating
 * migration's docblock for the full reasoning.
 *
 * `mark_uid` is the client-generated stable id every mark has always
 * carried (unchanged) — the client/server contract (marks matched by this
 * id across saves) needs no change; only where a mark is PERSISTED changed.
 *
 * Capture-ledger rework, 2026-09-11 — Johan: "the highlighter mark IS the
 * ledger line." `entry_type`/`entry_date`/`entry_description`/`entry_amount`
 * turn a mark into an affordability-ledger entry when `entry_type` is
 * 'income' or 'expense'; 'annotation' (the default — every mark before this
 * work, and every plain highlight/note drawn after it) means "not a ledger
 * line, never shown in the panel." `document_id`/`page`/`type` are now
 * nullable so an UNANCHORED entry (typed manually, or migrated from the old
 * separate income/expense-item tables) can live in this same table with no
 * document, no page, no drawn geometry at all — see the migration's own
 * docblock. `source`/`confidence` are pre-existing, reserved for a future
 * OCR decision Johan has not made — untouched, unpopulated, unreferenced by
 * this feature.
 */
class RentalApplicationDocumentMark extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const ENTRY_TYPE_INCOME = 'income';
    public const ENTRY_TYPE_EXPENSE = 'expense';
    public const ENTRY_TYPE_ANNOTATION = 'annotation';
    public const LEDGER_ENTRY_TYPES = [self::ENTRY_TYPE_INCOME, self::ENTRY_TYPE_EXPENSE];

    protected $fillable = [
        'agency_id', 'document_id', 'rental_application_id', 'mark_uid', 'type', 'page',
        'points', 'width', 'x', 'y', 'text',
        'highlighter_id', 'author_user_id', 'author_name', 'author_role',
        'source', 'confidence',
        'entry_type', 'entry_date', 'entry_description', 'entry_amount',
    ];

    protected $casts = [
        'points' => 'array',
        'x' => 'float',
        'y' => 'float',
        'width' => 'integer',
        'page' => 'integer',
        'confidence' => 'float',
        'entry_date' => 'date:Y-m-d',
        'entry_amount' => 'decimal:2',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }

    public function highlighter(): BelongsTo
    {
        return $this->belongsTo(RentalApplicationHighlighter::class, 'highlighter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isLedgerEntry(): bool
    {
        return in_array($this->entry_type, self::LEDGER_ENTRY_TYPES, true);
    }

    public function isAnchored(): bool
    {
        return $this->document_id !== null;
    }

    /**
     * The exact snake_case array shape marks_json has always used —
     * unchanged, so firstPagePreview()/remainingPagePreviews()'s JSON
     * response, and the burn/legend rendering that already consumes this
     * shape, need no changes. Not simply toArray() — this is a stable
     * wire contract independent of column additions.
     */
    public function toMarkArray(): array
    {
        $base = [
            'id' => $this->mark_uid,
            'type' => $this->type,
            'highlighter_id' => $this->highlighter_id,
            'author_user_id' => $this->author_user_id,
            'author_name' => $this->author_name,
            'author_role' => $this->author_role,
            'document_id' => $this->document_id,
            // Stage 2, 2026-09-11 — the capture panel's row-click-to-jump
            // needs a mark's page to scroll it into view; every other
            // existing consumer already gets page from the OUTER key of the
            // {pageIndex: [...marks]} shape firstPagePreview()/
            // remainingPagePreviews() return (see
            // RentalApplicationDocumentHighlightService::firstPagePreview()),
            // so this is a purely additive field, never read by them.
            'page' => $this->page,
            // Capture-ledger rework — carried on every mark (default
            // 'annotation') so the client can tell a plain highlight/note
            // apart from a ledger entry without a second lookup.
            'entry_type' => $this->entry_type,
            'entry_date' => $this->entry_date?->format('Y-m-d'),
            'entry_description' => $this->entry_description,
            'entry_amount' => $this->entry_amount !== null ? (float) $this->entry_amount : null,
        ];

        if ($this->type === 'note') {
            return $base + [
                'x' => $this->x,
                'y' => $this->y,
                'text' => $this->text,
            ];
        }

        return $base + [
            'points' => $this->points,
            'width' => $this->width,
        ];
    }
}
