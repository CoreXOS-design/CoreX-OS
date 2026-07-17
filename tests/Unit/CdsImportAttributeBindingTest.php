<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AT-177 (Johan, 2026-07-17) — the CDS importer binds a party marker's ATTRIBUTE from the words
 * around it, instead of collapsing every seller marker to the party name.
 *
 * These are the EATS's real label wordings (the ones that previously slipped the narrow anchored
 * patterns and defaulted to the name). identifyField must now return the right contact column.
 */
final class CdsImportAttributeBindingTest extends TestCase
{
    private function identify(string $before, string $after = '', string $clause = ''): array
    {
        $m = new ReflectionMethod(CdsParserService::class, 'identifyField');
        $m->setAccessible(true);

        return $m->invoke(new CdsParserService(), $before, $after, $clause);
    }

    /** The four seller attributes bind to their column, NOT to contact.full_names. */
    public function test_seller_attribute_labels_bind_to_their_column(): void
    {
        $this->assertSame('contact.address',   $this->identify('Seller 1 - Physical address:')['field_name']);
        $this->assertSame('contact.phone',     $this->identify('Seller 1 - Telephone number:')['field_name']);
        $this->assertSame('contact.email',     $this->identify('Seller 1 - Email address:')['field_name']);
        $this->assertSame('contact.id_number', $this->identify('Seller 1 - ID number / Passport number:')['field_name']);
    }

    /** "Telephone number:" is the exact wording the anchored /tel:$/ pattern missed — the regression. */
    public function test_the_telephone_wording_that_previously_slipped_now_binds(): void
    {
        $r = $this->identify('Seller 2 - Telephone number:');
        $this->assertSame('contact.phone', $r['field_name']);
        $this->assertNotSame('contact.full_names', $r['field_name']);
    }

    /** A full-name marker still binds to the name — not broken by the new attribute resolver. */
    public function test_name_labels_still_bind_to_name(): void
    {
        $this->assertSame('contact.full_names', $this->identify('Seller 1 - Full name and surname:')['field_name']);
    }

    /** Price "in words" context binds to the words variable, not the figure. */
    public function test_price_in_words_context_binds_to_words(): void
    {
        $this->assertSame('property.price_in_words', $this->identify('Asking price in words:')['field_name']);
    }

    /** A label with no attribute keyword is left to the manual/label fallback (unbound). */
    public function test_unknown_label_stays_unbound(): void
    {
        $this->assertSame('', $this->identify('Marital status:')['field_name']);
    }
}
