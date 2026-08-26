<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Docuperfect\DocxParserService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AT-177 (Johan, 2026-07-17) — THE importer fix, on the ACTUAL .docx path.
 *
 * "Claude import 1" is a .docx imported through DocxParserService + Claude AI (not CdsParserService).
 * The AI/regex detector binds a seller marker to the party name; bindAttributesFromContext() then
 * REFINES that generic binding to the attribute its surrounding words name — deterministically, on
 * the real field shape (context_before / suggested_key). It must never override a specific binding.
 */
final class DocxContextAttributeBindingTest extends TestCase
{
    private function bind(array $fields): array
    {
        $m = new ReflectionMethod(DocxParserService::class, 'bindAttributesFromContext');
        $m->setAccessible(true);

        return $m->invoke(new DocxParserService(), $fields);
    }

    private function field(string $contextBefore, string $suggestedKey, string $label = ''): array
    {
        return ['context_before' => $contextBefore, 'context_after' => '', 'suggested_label' => $label, 'suggested_key' => $suggestedKey];
    }

    /** A seller marker the detector bound to the NAME is refined to its real attribute by context. */
    public function test_generic_name_binding_is_refined_to_the_context_attribute(): void
    {
        $out = $this->bind([
            $this->field('Seller 1 - Physical address:', 'contact.full_names'),
            $this->field('Seller 1 - Telephone number:', 'contact.full_names'),
            $this->field('Seller 1 - Email address:',    'contact.full_names'),
            $this->field('Seller 1 - ID number / Passport:', 'contact.full_names'),
        ]);

        $this->assertSame('contact.address',   $out[0]['suggested_key']);
        $this->assertSame('contact.phone',     $out[1]['suggested_key']);
        $this->assertSame('contact.email',     $out[2]['suggested_key']);
        $this->assertSame('contact.id_number', $out[3]['suggested_key']);
    }

    /** A custom/unbound blank with attribute context is bound too. */
    public function test_custom_and_empty_bindings_are_refined(): void
    {
        $out = $this->bind([
            $this->field('Physical address of the seller', 'custom.seller_1_physical_address'),
            $this->field('Email:', ''),
        ]);

        $this->assertSame('contact.address', $out[0]['suggested_key']);
        $this->assertSame('contact.email',   $out[1]['suggested_key']);
    }

    /** THE GUARD: an already-specific binding is NEVER overridden (e.g. a property address stays property). */
    public function test_specific_bindings_are_never_touched(): void
    {
        $out = $this->bind([
            $this->field('Property physical address:', 'property.address'),   // has "address" but already property
            $this->field('Seller address:',            'contact.address'),    // already correct
            $this->field('Erf / Unit number:',         'property.erf_number'),
        ]);

        $this->assertSame('property.address',    $out[0]['suggested_key'], 'a specific property binding must not be flipped to contact');
        $this->assertSame('contact.address',     $out[1]['suggested_key']);
        $this->assertSame('property.erf_number', $out[2]['suggested_key']);
    }

    /** A name marker stays the name — refinement only fires on an attribute keyword. */
    public function test_name_marker_stays_name(): void
    {
        $out = $this->bind([$this->field('Seller 1 - Full name and surname:', 'contact.full_names')]);
        $this->assertSame('contact.full_names', $out[0]['suggested_key']);
    }

    /** Price "in words" context → the words variable. */
    public function test_price_in_words_binds_to_words(): void
    {
        $out = $this->bind([$this->field('Asking price in words:', 'custom.asking_price_in_words')]);
        $this->assertSame('property.price_in_words', $out[0]['suggested_key']);
    }

    /** No attribute keyword → left for the human vet (unchanged). */
    public function test_unknown_context_is_left_alone(): void
    {
        $out = $this->bind([$this->field('Marital status:', 'custom.marital_status')]);
        $this->assertSame('custom.marital_status', $out[0]['suggested_key']);
    }
}
