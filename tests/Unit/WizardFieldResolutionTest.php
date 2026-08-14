<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WebTemplateDataService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AT-177 wizard walk — the resolution RULES behind three bugs on Johan's real doc (Claude import 1):
 *   B2 — party-bound custom fields rendered the party NAME instead of the attribute; the attribute is
 *        now derived from the field LABEL (name/surname/id/address/tel/email keyword map).
 *   B3 — an "… in words" price spot rendered the figure; it now runs the rand converter.
 *
 * These are the pure resolution helpers; the full CDS render is proven on qa against 380 Wilfred.
 */
final class WizardFieldResolutionTest extends TestCase
{
    private function call(string $method, ...$args)
    {
        $m = new ReflectionMethod(WebTemplateDataService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(new WebTemplateDataService(), ...$args);
    }

    /** B2 — Johan's exact three fields resolve to their ATTRIBUTE, not the name. */
    public function test_party_bound_labels_resolve_to_the_right_attribute(): void
    {
        $this->assertSame('address',   $this->call('contactAttributeFromLabel', 'Seller - Physical address'));
        $this->assertSame('phone',     $this->call('contactAttributeFromLabel', 'Seller - Telephone'));
        $this->assertSame('email',     $this->call('contactAttributeFromLabel', 'Seller - Email'));
        $this->assertSame('id_number', $this->call('contactAttributeFromLabel', 'Seller - ID number'));
        $this->assertSame('last_name', $this->call('contactAttributeFromLabel', 'Seller - Surname'));
        $this->assertSame('name',      $this->call('contactAttributeFromLabel', 'Seller - Full name and surname'));
    }

    /** "Physical address" must NOT be mis-read as a name just because "name"-ish words float around. */
    public function test_the_most_specific_keyword_wins(): void
    {
        $this->assertSame('address',   $this->call('contactAttributeFromLabel', 'Physical address of the seller'));
        $this->assertSame('id_number', $this->call('contactAttributeFromLabel', 'ID Number / Passport'));
        // surname beats the generic "name"
        $this->assertSame('last_name', $this->call('contactAttributeFromLabel', 'Surname (family name)'));
    }

    /** A label that names no attribute leaves resolution untouched (null → keep whatever was mapped). */
    public function test_an_unrecognised_label_returns_null(): void
    {
        $this->assertNull($this->call('contactAttributeFromLabel', 'Marital status'));
        $this->assertNull($this->call('contactAttributeFromLabel', ''));
    }

    /** The known-attribute guard: an explicit mapping is respected; a bare role/blank is not. */
    public function test_known_attribute_guard(): void
    {
        $this->assertTrue($this->call('isKnownContactAttribute', 'address'));
        $this->assertTrue($this->call('isKnownContactAttribute', 'id_number'));
        $this->assertFalse($this->call('isKnownContactAttribute', ''));
        $this->assertFalse($this->call('isKnownContactAttribute', 'seller'));   // a role, not an attribute
        $this->assertFalse($this->call('isKnownContactAttribute', 'contact'));  // the generic key
    }

    /** B3 — the "in words" spots are recognised; the plain figure spots are not. */
    public function test_label_wants_words(): void
    {
        $this->assertTrue($this->call('labelWantsWords', 'Asking price in words'));
        $this->assertTrue($this->call('labelWantsWords', 'Amount in Words'));
        $this->assertFalse($this->call('labelWantsWords', 'Asking price (Rand)'));
        $this->assertFalse($this->call('labelWantsWords', 'Purchase price'));
    }
}
