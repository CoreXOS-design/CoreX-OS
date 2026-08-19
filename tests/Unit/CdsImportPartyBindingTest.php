<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AT-359 (Johan, 2026-08-03) — the CDS importer badly mis-bound a lease's PARTY fields:
 *   - the Lessor/Lessee NAME blank ("@@@@ (Lessor / Landlord)") bound to deal.amount_words,
 *   - the address / ID blanks bound to generic contact.* with NO Lessor/Lessee discrimination.
 *
 * Two additive, regression-guarded fixes:
 *   A — identifyField's "(...)" amount-in-words pattern is guarded so a trailing PARTY-ROLE
 *       descriptor never mis-binds to deal.amount_words, while the genuine currency idiom stays.
 *   B — a new post-pass reads the inline "(Lessor / Landlord)" descriptor and prefixes the block's
 *       party-less contact fields to lessor_/lessee_/seller_/buyer_.
 *
 * These tests are the regression guard for a pipeline-adjacent file: they prove the lease is fixed
 * AND that a known-good structure (the EATS mandate's mid-sentence "(Seller)") is byte-identical.
 */
final class CdsImportPartyBindingTest extends TestCase
{
    private function identify(string $before, string $after = '', string $clause = ''): array
    {
        $m = new ReflectionMethod(CdsParserService::class, 'identifyField');
        $m->setAccessible(true);

        return $m->invoke(new CdsParserService(), $before, $after, $clause);
    }

    private function assignParty(array $sections): array
    {
        $m = new ReflectionMethod(CdsParserService::class, 'assignPartyFromInlineDescriptor');
        $m->setAccessible(true);

        return $m->invoke(new CdsParserService(), $sections);
    }

    /** One paragraph section: text pieces and field placeholders, in order. */
    private function para(array $items): array
    {
        $content = [];
        foreach ($items as $it) {
            if (is_string($it)) {
                $content[] = ['type' => 'text', 'value' => $it];
            } else {
                // ['field' => 'contact.address'] → a bound field_placeholder
                $content[] = ['type' => 'field_placeholder', 'marker' => 'input', 'field_name' => $it['field']];
            }
        }

        return ['type' => 'paragraph', 'content' => $content];
    }

    /** Flatten a section's field_placeholder bindings, in order. */
    private function fieldNames(array $section): array
    {
        return array_values(array_map(
            fn ($i) => $i['field_name'] ?? '',
            array_filter($section['content'] ?? [], fn ($i) => ($i['type'] ?? '') === 'field_placeholder')
        ));
    }

    // ── Fix A — amount-words pattern guard ──────────────────────────────────────────────

    /** A trailing party-role descriptor after a name blank must NOT bind to deal.amount_words. */
    public function test_role_descriptor_after_field_is_not_amount_words(): void
    {
        $this->assertNotSame('deal.amount_words', $this->identify('', '(Lessor / Landlord)')['field_name']);
        $this->assertNotSame('deal.amount_words', $this->identify('', '(Lessee / tenant / Occupant)')['field_name']);
        $this->assertNotSame('deal.amount_words', $this->identify('', '(Seller)')['field_name']);
        $this->assertNotSame('deal.amount_words', $this->identify('', '(Purchaser / Buyer)')['field_name']);
    }

    /**
     * The genuine currency in-words idiom still binds to deal.amount_words (regression kept). The
     * guard requires the words-blank to sit in a currency context: the text immediately before the
     * "(" names an amount ("Amount", "… rental amount"), which the role-descriptor case never does.
     */
    public function test_currency_in_words_still_binds_amount_words(): void
    {
        $this->assertSame('deal.amount_words', $this->identify('Amount', '(in words)')['field_name']);
        $this->assertSame('deal.amount_words', $this->identify('rental amount', '(in words)')['field_name']);
    }

    // ── Fix B — party from inline descriptor ────────────────────────────────────────────

    /** The lease Parties block: Lessor then Lessee, each name/address/ID prefixed to its party. */
    public function test_lease_party_block_binds_lessor_then_lessee(): void
    {
        $sections = [
            $this->para(['PARTIES']),
            // Lessor: name blank, then the descriptor; then address; then ID.
            $this->para([['field' => ''], ' (Lessor / Landlord)']),
            $this->para(['Of (address) ', ['field' => 'contact.address']]),
            $this->para(['ID/Passport/Registration No: ', ['field' => 'contact.id_number']]),
            $this->para(['AND']),
            // Lessee block.
            $this->para([['field' => 'deal.amount_words'], ' (Lessee / tenant / Occupant)']),
            $this->para(['Of (address) ', ['field' => 'contact.address']]),
            $this->para(['ID/Passport/Registration No: ', ['field' => 'contact.id_number']]),
        ];

        $out = $this->assignParty($sections);

        $this->assertSame(['lessor_name'],        $this->fieldNames($out[1]));
        $this->assertSame(['lessor_address'],     $this->fieldNames($out[2]));
        $this->assertSame(['lessor_id_number'],   $this->fieldNames($out[3]));
        $this->assertSame(['lessee_name'],        $this->fieldNames($out[5]));
        $this->assertSame(['lessee_address'],     $this->fieldNames($out[6]));
        $this->assertSame(['lessee_id_number'],   $this->fieldNames($out[7]));
    }

    /**
     * GOLDEN / ZERO-REGRESSION — the EATS mandate's identity clause states the role mid-sentence
     * ("...of the owner/s (Seller) of the...") in a LONG paragraph. The descriptor is neither
     * trailing nor short, so NO party is assigned: the fields are byte-identical before/after,
     * even though they use eligible contact attributes (which proves the trailing+short guard, not
     * mere field-eligibility, is what protects the known-good doc).
     */
    public function test_eats_midsentence_seller_is_byte_identical(): void
    {
        $sections = [
            $this->para([
                'I / We ',
                ['field' => 'contact.full_names'],
                ['field' => 'contact.id_number'],
                ', the undersigned, being the registered owner/s, or duly authorised '
                . 'representative/s of the owner/s (Seller) of the property described below',
            ]),
        ];

        $before = json_encode($sections);
        $after  = json_encode($this->assignParty($sections));

        $this->assertSame($before, $after, 'EATS mid-sentence (Seller) clause must be untouched.');
    }

    /** A property-location field with no governing party descriptor is never touched. */
    public function test_property_fields_without_descriptor_untouched(): void
    {
        $sections = [
            $this->para(['Erf no: ', ['field' => 'property.erf_number'], ' Street: ', ['field' => 'property.street']]),
        ];

        $out = $this->assignParty($sections);

        $this->assertSame(['property.erf_number', 'property.street'], $this->fieldNames($out[0]));
    }

    /** A field already bound to a party keeps its binding (never re-prefixed). */
    public function test_already_party_bound_field_is_preserved(): void
    {
        $sections = [
            $this->para([['field' => 'seller_address'], ' (Lessor / Landlord)']),
        ];

        $out = $this->assignParty($sections);

        $this->assertSame(['seller_address'], $this->fieldNames($out[0]));
    }
}
