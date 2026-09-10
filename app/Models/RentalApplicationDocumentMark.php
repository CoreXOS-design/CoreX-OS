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
 */
class RentalApplicationDocumentMark extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id', 'document_id', 'mark_uid', 'type', 'page',
        'points', 'width', 'x', 'y', 'text',
        'highlighter_id', 'author_user_id', 'author_name', 'author_role',
        'source', 'confidence',
    ];

    protected $casts = [
        'points' => 'array',
        'x' => 'float',
        'y' => 'float',
        'width' => 'integer',
        'page' => 'integer',
        'confidence' => 'float',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function highlighter(): BelongsTo
    {
        return $this->belongsTo(RentalApplicationHighlighter::class, 'highlighter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
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
