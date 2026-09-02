<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Contact;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tags EXISTING demo contacts with a contact type, using the real
 * `contact_types` system as CoreX defines it (App\Models\ContactType::
 * CANONICAL + ::EXTRA_PARENTS — Seller/Buyer/Lessor/Lessee/Owner/Other,
 * ensured present by DemoDataSeeder::backfillContactTypes()). No invented
 * types.
 *
 * Contacts with `is_buyer=1` (already set by earlier demo stages — 132 of
 * 290 live) are tagged Buyer, matching the existing signal rather than
 * fighting it. The remaining contacts are split deterministically across
 * Seller / Lessor / Lessee / Owner / Other in realistic proportions for an
 * estate agency's contact book.
 *
 * ContactController@index's `?type=` filter does NOT read contact_type_id
 * for Buyer/Seller/Lessor — it reads `is_buyer` for Buyer, and a
 * `contact_property` pivot row (role='seller' / role IN ('landlord',
 * 'lessor')) for Seller/Lessor specifically (AT-91 correction, confirmed by
 * reading the controller directly — 'owner'-role pivot rows are NOT a
 * synonym for Seller). So tagging alone would badge the contact correctly
 * but leave the Seller/Lessor FILTER dropdown empty. This seeder also
 * writes the `contact_property` link the filter actually reads for
 * every Seller/Lessor-tagged contact, against a real agency property.
 *
 * IDEMPOTENT BY CONSTRUCTION:
 *   - Bucket assignment is a pure function of contact id (sorted ascending,
 *     positional index into a fixed-proportion list) — identical every run.
 *   - Contact::syncTypeAssignments() is Eloquent sync() under the hood —
 *     converges to the same pivot rows, never duplicates.
 *   - contact_property links are guarded by an explicit existence check on
 *     (contact_id, role) before insert.
 */
class DemoContactTypesSeeder
{
    /** @return array{tagged:int, property_links:int, note?:string} */
    public function run(int $agencyId = 1): array
    {
        $typeIds = DB::table('contact_types')
            ->whereIn('name', ['Seller', 'Buyer', 'Lessor', 'Lessee', 'Owner', 'Other'])
            ->whereNull('deleted_at')
            ->pluck('id', 'name');

        foreach (['Seller', 'Buyer', 'Lessor', 'Lessee', 'Owner', 'Other'] as $needed) {
            if (!$typeIds->has($needed)) {
                return ['tagged' => 0, 'property_links' => 0, 'note' => "Skipped — contact_types row '{$needed}' missing (backfillContactTypes must run first)."];
            }
        }

        $contacts = DB::table('contacts')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'is_buyer', 'contact_type_id']);

        if ($contacts->isEmpty()) {
            return ['tagged' => 0, 'property_links' => 0, 'note' => 'Skipped — agency has no contacts.'];
        }

        $propertyIds = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        // Realistic non-buyer proportions (of the non-buyer remainder):
        // Seller 40%, Lessor 20%, Lessee 20%, Owner 12%, Other 8%.
        $nonBuyerPlan = array_merge(
            array_fill(0, 40, 'Seller'),
            array_fill(0, 20, 'Lessor'),
            array_fill(0, 20, 'Lessee'),
            array_fill(0, 12, 'Owner'),
            array_fill(0, 8,  'Other'),
        );
        $planLen = count($nonBuyerPlan);

        $tagged = 0;
        $propertyLinks = 0;
        $nonBuyerCursor = 0;

        foreach ($contacts as $contact) {
            // Already tagged (e.g. a prior partial run under a different
            // scheme) — leave it alone, don't fight an operator's manual edit.
            if ($contact->contact_type_id !== null) {
                continue;
            }

            if ($contact->is_buyer) {
                $typeName = 'Buyer';
            } else {
                $typeName = $nonBuyerPlan[$nonBuyerCursor % $planLen];
                $nonBuyerCursor++;
            }

            $typeId = (int) $typeIds[$typeName];

            $model = Contact::withoutGlobalScopes()->find($contact->id);
            if (!$model) {
                continue;
            }
            $model->syncTypeAssignments([$typeId], []);
            $tagged++;

            if (in_array($typeName, ['Seller', 'Lessor'], true) && !empty($propertyIds)) {
                $role = $typeName === 'Seller' ? 'seller' : 'landlord';
                $exists = DB::table('contact_property')
                    ->where('contact_id', $contact->id)
                    ->where('role', $role)
                    ->exists();
                if (!$exists) {
                    $propertyId = $propertyIds[$contact->id % count($propertyIds)];
                    DB::table('contact_property')->insert([
                        'contact_id'  => $contact->id,
                        'property_id' => $propertyId,
                        'role'        => $role,
                        'is_primary'  => 1,
                        'source'      => 'demo_seed',
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                    $propertyLinks++;
                }
            }
        }

        $note = "Contact types: +{$tagged} tagged, +{$propertyLinks} seller/landlord property links.";

        return ['tagged' => $tagged, 'property_links' => $propertyLinks, 'note' => $note];
    }
}
