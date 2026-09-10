<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data repair — document display names carrying a path separator.
 *
 * The PDF splitter composes a document's display name as
 * "Subject · DocType · Date.pdf". One document-type label is literally
 * "IDs / Identity", so its slash landed inside the stored filename.
 * Symfony's Content-Disposition builder rejects "/" and "\" outright, so
 * every download or inline view of such a document threw and rendered the
 * 500 page — the stored bytes were always fine, only the name was unusable.
 *
 * The class is prevented going forward by Document::sanitizeOriginalName()
 * (a mutator on original_name, so no writer can reintroduce one) and by the
 * splitter sanitising its baseName before its duplicate-name check. This
 * migration repairs the rows already written, including soft-deleted ones,
 * so historic documents open again.
 *
 * Idempotent: rows with no separator are not matched and not rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('documents')
            ->where(function ($q) {
                $q->where('original_name', 'like', '%/%')
                  ->orWhere('original_name', 'like', '%\\\\%');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $clean = trim(str_replace(['/', '\\'], '-', (string) $row->original_name));

                    if ($clean === '' || $clean === $row->original_name) {
                        continue;
                    }

                    DB::table('documents')->where('id', $row->id)->update([
                        'original_name' => $clean,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design: the original strings were unusable filenames
        // and the separator's original position is not recoverable from the
        // repaired value. Nothing is dropped, so there is nothing to restore.
    }
};
