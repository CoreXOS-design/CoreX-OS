<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** Shared people and property of every context come from config('template-demo-sample'); a context adds only what is its own. */
abstract class AbstractDemoContext implements DemoContext
{
    public function documentContext(Template $t): string
    {
        return $t->isSalesDocument() ? 'sales' : 'rental';
    }

    public function parties(): array
    {
        return [];
    }

    public function vars(): array
    {
        return [];
    }

    public function patterns(): array
    {
        return [];
    }

    /** The landlord's and tenant's names under every name a rental document's blanks use. */
    protected static function rentalNames(): array
    {
        return [
            'lessor_full' => self::person('landlord'), 'lessee_full' => self::person('tenant'),
            'lessor_name' => self::person('landlord'), 'lessee_name' => self::person('tenant'),
            'landlord_full' => self::person('landlord'), 'tenant_full' => self::person('tenant'),
        ];
    }

    /** The seller's and buyer's names under every name a sales document's blanks use. */
    protected static function salesNames(): array
    {
        return [
            'seller_full' => self::person('seller'), 'buyer_full' => self::person('buyer'),
            'seller_name' => self::person('seller'), 'buyer_name' => self::person('buyer'),
            'purchaser_full' => self::person('buyer'), 'purchaser_name' => self::person('buyer'),
        ];
    }

    protected static function person(string $role): string
    {
        return (string) config('template-demo-sample.people.' . $role . '.name', 'Sample Person');
    }

    protected static function slugIn(?string $slug, array $slugs): bool
    {
        return $slug !== null && in_array($slug, $slugs, true);
    }

    protected static function nameHas(Template $t, array $words): bool
    {
        $n = mb_strtolower((string) $t->name);
        foreach ($words as $w) {
            // a word that starts here ("disclosure" also finds "disclosures"; "otp" does not find "hotpot")
            if (preg_match('/\\b' . preg_quote($w, '/') . '/u', $n) === 1) {
                return true;
            }
        }

        return false;
    }
}
