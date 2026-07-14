<?php

use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\DocumentTypeClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Classify the templates nobody ever classified.
 *
 * `Template::isEsignBlocked()` blocks alienation documents from e-signing under ECTA §13(1) —
 * a sale e-signed is VOID — and its strongest layer reads the template's document-type slug.
 * That layer works: five live OTPs are blocked by it today.
 *
 * But NOTHING EVER CLASSIFIED A TEMPLATE. The importer wrote `document_type_id = null` on every
 * document it created, and 17 live templates are unclassified — including
 * **"Contract of Sale - Serenity Hills Eco Estate"**, a deed of alienation whose only
 * protection is a name regex. Rename it and the legal block evaporates.
 *
 * An unclassified sale is protected by what it is CALLED. A classified sale is protected by
 * what it IS. This backfills the difference.
 *
 * Runs as a MIGRATION, not a seeder: seeders do not run on a `git pull` deploy (AT-162), and a
 * legal control that only exists on the machine where someone remembered to run a seeder is not
 * a control at all.
 *
 * Conservative and idempotent:
 *   - touches ONLY rows where document_type_id IS NULL — never re-classifies a human's decision;
 *   - writes nothing when the classifier cannot tell (null is a safe answer: the name regex
 *     still guards it);
 *   - safe to run twice.
 */
return new class extends Migration
{
    /**
     * The five document types the ECTA block depends on.
     *
     * FOUR OF THESE EXIST ON LIVE ONLY BECAUSE SOMEONE TYPED THEM IN. `otp`, `sale_agreement`,
     * `deed_of_sale` and `deed_of_alienation` appear in NO migration and NO seeder — they are
     * on live, staging and qa1 purely because staging and qa1 are clones of live.
     *
     * So on any environment built from the code — a `migrate:fresh`, the demo, a new agency, the
     * test database — the document types that `isEsignBlocked()` reads DO NOT EXIST, and a deed
     * of alienation cannot be classified as one. A legal control whose reference data only lives
     * on one machine is not a control (AT-162: seeders do not run on a `git pull` deploy, which
     * is why this is a migration backfill and not a seeder).
     *
     * @var array<string, string> slug => label
     */
    private const REQUIRED_TYPES = [
        'otp'                => 'OTP',
        'offer_to_purchase'  => 'Offer to Purchase',
        'sale_agreement'     => 'Sale Agreement',
        'deed_of_sale'       => 'Deed of Sale',
        'deed_of_alienation' => 'Deed of Alienation',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('docuperfect_templates') || ! Schema::hasTable('document_types')) {
            return;
        }

        // 1. Make the wet-ink document types exist, everywhere. Idempotent.
        $hasGrouping = Schema::hasColumn('document_types', 'grouping');
        $sort = 900;

        foreach (self::REQUIRED_TYPES as $slug => $label) {
            $exists = DB::table('document_types')->where('slug', $slug)->exists();
            if ($exists) {
                continue;
            }

            $row = [
                'slug'       => $slug,
                'label'      => $label,
                'sort_order' => $sort++,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasGrouping) {
                $row['grouping'] = 'shared';
            }

            DB::table('document_types')->insert($row);

            Log::info('Migration: created missing wet-ink document type', ['slug' => $slug]);
        }

        // 2. Classify the templates nobody ever classified.
        $classifier = app(DocumentTypeClassifier::class);

        $unclassified = Template::query()
            ->withoutGlobalScopes()
            ->whereNull('document_type_id')
            ->get(['id', 'name']);

        $classified = 0;
        $blocked = 0;

        foreach ($unclassified as $template) {
            $typeId = $classifier->classifyToId((string) $template->name);

            if ($typeId === null) {
                continue;   // cannot tell — leave it null, the name regex still guards it
            }

            // saveQuietly-equivalent: a raw update, so the model's saving guard and any
            // observers do not fire during a migration.
            DB::table('docuperfect_templates')
                ->where('id', $template->id)
                ->update(['document_type_id' => $typeId]);

            $classified++;

            // Anything that classifies as an alienation document must also be wet-ink NOW —
            // classification is what makes the block survive a rename, but the flag is what
            // the wizard reads today.
            $slug = DB::table('document_types')->where('id', $typeId)->value('slug');
            if (in_array($slug, ['otp', 'offer_to_purchase', 'sale_agreement', 'deed_of_sale', 'deed_of_alienation'], true)) {
                DB::table('docuperfect_templates')
                    ->where('id', $template->id)
                    ->update(['is_esign' => 0]);
                $blocked++;
            }
        }

        Log::info('Migration: classified previously-unclassified templates', [
            'examined'   => $unclassified->count(),
            'classified' => $classified,
            'forced_wet_ink' => $blocked,
        ]);
    }

    public function down(): void
    {
        // Deliberately NOT reversible. Un-classifying a deed of alienation would restore a
        // legal exposure — a sale whose e-sign block depends on its name. There is no
        // circumstance in which we want that back.
    }
};
