<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RMCP board approval letter: the Approval block's Title row said a fixed "Principal". It now reads {{rmcp.approved_by_title}}
 * (the signatories' titles from the signed board letter, or the title typed at Approve).
 *
 * Only DRAFT versions are changed. An approved or superseded version is a record of what was approved and is left exactly as it is.
 * Idempotent: it only touches a section that still holds the fixed word.
 */
return new class extends Migration
{
    public function up(): void
    {
        $draftIds = DB::table('rmcp_versions')->where('status', 'draft')->pluck('id');
        if ($draftIds->isEmpty()) {
            return;
        }

        DB::table('rmcp_sections')
            ->whereIn('rmcp_version_id', $draftIds)
            ->where('section_number', 'Approval')
            ->where('body_html', 'like', '%<th>Title</th><td>Principal</td>%')
            ->update([
                'body_html'  => DB::raw("REPLACE(body_html, '<th>Title</th><td>Principal</td>', '<th>Title</th><td>{{rmcp.approved_by_title}}</td>')"),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Not reversed: the old text was a fixed word, and an edited draft may already differ.
    }
};
