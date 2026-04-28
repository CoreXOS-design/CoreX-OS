<?php

use App\Models\ContactMatch;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->string('share_slug', 120)->nullable()->after('share_token');
            $table->unique('share_slug', 'cm_share_slug_unique');
        });

        // Backfill existing matches
        ContactMatch::withoutGlobalScope(AgencyScope::class)
            ->with('contact')
            ->whereNull('share_slug')
            ->get()
            ->each(function (ContactMatch $m) {
                $m->share_slug = self::buildSlug($m);
                $m->saveQuietly();
            });
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropUnique('cm_share_slug_unique');
            $table->dropColumn('share_slug');
        });
    }

    public static function buildSlug(ContactMatch $m): string
    {
        $base = trim(($m->contact->first_name ?? '') . ' ' . ($m->contact->last_name ?? ''));
        $base = $base !== '' ? Str::slug($base) : 'match';

        // Ensure uniqueness by appending a short random suffix
        do {
            $candidate = $base . '-' . strtolower(Str::random(5));
            $exists = \DB::table('contact_matches')->where('share_slug', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }
};
