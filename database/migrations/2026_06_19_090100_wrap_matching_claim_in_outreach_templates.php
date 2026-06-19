<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-61 — wrap the per-property matching claim in existing outreach templates
 * in the {?matching_buyer_count}…{/matching_buyer_count} optional segment.
 *
 * AT-61 lets a pitch be composed off a contact's address with no linked
 * Property. In that mode there is no property type/beds/price, so the
 * "X of them are searching for THIS property" claim must NOT be emitted. The
 * composer signals this by leaving {matching_buyer_count} blank, which
 * collapses the optional segment — but only if the template wraps the clause.
 *
 * The seeder now ships wrapped bodies; this migration brings already-seeded
 * rows current. It only rewrites a template whose body still contains an
 * UNWRAPPED matching clause (exact known text) — customised bodies are left
 * untouched, and a body already wrapped is skipped.
 */
return new class extends Migration
{
    /** Exact unwrapped clauses shipped before AT-61, and their wrapped form. */
    private function rewrites(): array
    {
        $whatsappOld = ', and {matching_buyer_count} of them are specifically searching for {property_beds}-bedroom {property_type}s in your price range';
        $emailOld    = ', and {matching_buyer_count} are specifically looking for {property_beds}-bedroom {property_type}s in your price range';

        return [
            $whatsappOld => '{?matching_buyer_count}' . $whatsappOld . '{/matching_buyer_count}',
            $emailOld    => '{?matching_buyer_count}' . $emailOld . '{/matching_buyer_count}',
        ];
    }

    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('seller_outreach_templates')) {
            return;
        }

        $rewrites = $this->rewrites();

        DB::table('seller_outreach_templates')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function ($tpl) use ($rewrites) {
                $body = (string) $tpl->body;

                // Already wrapped → nothing to do.
                if (str_contains($body, '{?matching_buyer_count}')) {
                    return;
                }

                $new = strtr($body, $rewrites);
                if ($new !== $body) {
                    DB::table('seller_outreach_templates')
                        ->where('id', $tpl->id)
                        ->update(['body' => $new, 'updated_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('seller_outreach_templates')) {
            return;
        }

        // Reverse: strip the wrapper tokens we added (leave the clause text).
        DB::table('seller_outreach_templates')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function ($tpl) {
                $body = (string) $tpl->body;
                if (!str_contains($body, '{?matching_buyer_count}')) {
                    return;
                }
                $new = str_replace(
                    ['{?matching_buyer_count}', '{/matching_buyer_count}'],
                    ['', ''],
                    $body
                );
                if ($new !== $body) {
                    DB::table('seller_outreach_templates')
                        ->where('id', $tpl->id)
                        ->update(['body' => $new, 'updated_at' => now()]);
                }
            });
    }
};
