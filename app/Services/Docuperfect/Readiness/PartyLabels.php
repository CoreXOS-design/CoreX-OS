<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Readiness;

use App\Models\Docuperfect\Template;

/**
 * Plain names for a template's signers, for every workshop screen (readiness, demo): the words the document itself uses - "Lessor (landlord)",
 * "Lessee (tenant)", "Seller", "Buyer", "Agent" - never the internal keys (owner_party, acquiring_party). Uses the same label source as the
 * signing pages (Template::roleDisplayLabel); when the document is not clearly a sale or a rental it says "Owner" / "Other party".
 */
final class PartyLabels
{
    public static function label(Template $t, string $token): string
    {
        $key = mb_strtolower(trim($token));
        $category = $t->resolvedTransactionCategory();
        if ($category === null) {
            $plain = ['owner_party' => 'Owner', 'acquiring_party' => 'Other party', 'agent' => 'Agent', 'landlord' => 'Landlord', 'lessor' => 'Landlord', 'tenant' => 'Tenant', 'lessee' => 'Tenant', 'seller' => 'Seller', 'buyer' => 'Buyer'];

            return $plain[$key] ?? ucfirst(str_replace('_', ' ', $key));
        }
        $label = Template::roleDisplayLabel($key, $category === 'sales');
        if ($category !== 'sales') {
            if ($label === 'Lessor') {
                return 'Lessor (landlord)';
            }
            if ($label === 'Lessee') {
                return 'Lessee (tenant)';
            }
        }

        return $label;
    }
}
