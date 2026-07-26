<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 — Assistant Control Page: "may this assistant download documents?"
 *
 * A per-assignment behaviour toggle the AGENT controls on their assistant's control page, in the
 * same mould as can_manage_my_records / show_attribution / notify_on_action. When ON (the default,
 * matching prior behaviour), the assistant may download document files anywhere they can already
 * reach them; when OFF, every document-download endpoint returns 403 (DenyAssistantDownload).
 *
 * Defaults TRUE so existing assistants keep the capability they have today — the agent switches it
 * off deliberately, it is never removed silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_assignments', function (Blueprint $t) {
            if (!Schema::hasColumn('assistant_assignments', 'can_download_documents')) {
                $t->boolean('can_download_documents')->default(true)->after('notify_on_action');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assistant_assignments', function (Blueprint $t) {
            if (Schema::hasColumn('assistant_assignments', 'can_download_documents')) {
                $t->dropColumn('can_download_documents');
            }
        });
    }
};
