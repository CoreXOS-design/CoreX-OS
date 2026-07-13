<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-243 — backfill the deal↔party link for deals captured before it existed.
 *
 * Deals captured before `deal_contacts` recorded only a free-text `deals.buyer_name`,
 * while the actual contacts were linked to the PROPERTY. This reconnects the two where
 * the evidence is unambiguous, so the purchaser badge is true of the deals that already
 * exist rather than only of deals captured from today.
 *
 * THE RULE — evidence, never a guess:
 * a property-linked contact (role 'buyer') is recorded as a party on that property's deal
 * only when its full name is actually named in that deal's `buyer_name`. Joint buyers
 * ("Elize Reichel & John Smith") therefore both link. When nothing matches — the name was
 * never captured, or the buyer was never linked as a contact — the deal is left with NO
 * parties, and the property simply claims no purchaser. An unknown purchaser must read as
 * unknown; inventing one from a near-match would put the wrong person's name on a sale.
 *
 * This runs as a migration, not a command, so it travels with the deploy to every
 * environment automatically (AT-162: seeders/commands do not run on a `git pull` deploy).
 * It is idempotent — insertOrIgnore against the unique (deal, contact, role) index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $normalise = static function (?string $s): string {
            $s = mb_strtolower(trim((string) $s));
            $s = preg_replace('/[^a-z0-9\s]+/u', ' ', $s) ?? '';

            return trim(preg_replace('/\s+/', ' ', $s) ?? '');
        };

        $linked = 0;
        $skipped = 0;

        // Deals that have a property and a captured buyer name, and no parties yet.
        DB::table('deals')
            ->whereNull('deleted_at')
            ->whereNotNull('property_id')
            ->whereNotNull('buyer_name')
            ->where('buyer_name', '!=', '')
            ->orderBy('id')
            ->chunk(200, function ($deals) use ($normalise, &$linked, &$skipped) {
                foreach ($deals as $deal) {
                    $haystack = $normalise($deal->buyer_name);
                    if ($haystack === '') {
                        $skipped++;
                        continue;
                    }

                    // The property's buyer-role contacts — the candidate pool.
                    $candidates = DB::table('contact_property as cp')
                        ->join('contacts as c', 'c.id', '=', 'cp.contact_id')
                        ->where('cp.property_id', $deal->property_id)
                        ->whereRaw('LOWER(TRIM(cp.role)) = ?', ['buyer'])
                        ->whereNull('c.deleted_at')
                        ->select('c.id', 'c.first_name', 'c.last_name')
                        ->get();

                    $matched = [];
                    foreach ($candidates as $c) {
                        $full = $normalise(trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')));
                        if ($full === '') {
                            continue;
                        }
                        // Named in the deal's buyer_name → this person is a buyer ON THIS DEAL.
                        if (str_contains($haystack, $full)) {
                            $matched[] = (int) $c->id;
                        }
                    }

                    if (! $matched) {
                        $skipped++;
                        continue;
                    }

                    foreach (array_unique($matched) as $cid) {
                        DB::table('deal_contacts')->insertOrIgnore([
                            'deal_id'    => $deal->id,
                            'contact_id' => $cid,
                            'role'       => 'buyer',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $linked++;
                    }
                }
            });

        if ($linked || $skipped) {
            \Log::info('AT-243 deal_contacts backfill', [
                'buyer_links_created'      => $linked,
                'deals_left_unattributed'  => $skipped,
            ]);
        }
    }

    public function down(): void
    {
        // Only the backfilled buyer rows are ours to remove; a real capture writes the same
        // table, so we cannot distinguish them after the fact. Dropping the table (the
        // sibling migration's down()) is the clean reversal — this stays a no-op rather
        // than deleting parties a user captured by hand.
    }
};
