<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Docuperfect\DocumentImporterController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AT-177 Stage-1 gate — the CONVERGENCE-point fix (parser-agnostic).
 *
 * The gate failed because the earlier fixes patched individual parsers (CdsParser / DocxParser) but
 * the real import can go through others (ClaudeVision on a PDF, the regex fallback). This proves the
 * one place they all meet — DocumentImporterController::refineAttributeBindingsFromContext, run on
 * $result['fields'] before the draft is stored — refines the binding regardless of which parser
 * produced it, and across both field shapes ('context' string vs context_before/after).
 */
final class ImportConvergenceBindingTest extends TestCase
{
    private function refine(array $fields): array
    {
        $m = new ReflectionMethod(DocumentImporterController::class, 'refineAttributeBindingsFromContext');
        $m->setAccessible(true);

        return $m->invoke(app(DocumentImporterController::class), $fields);
    }

    /** ClaudeVision shape: a single 'context' string. Seller markers bound to name are refined. */
    public function test_claude_vision_context_string_shape_is_refined(): void
    {
        $out = $this->refine([
            ['context' => 'Seller 1 - Physical address: [___]', 'suggested_key' => 'contact.full_names'],
            ['context' => 'Seller 1 - Telephone number: [___]', 'suggested_key' => 'contact.full_names'],
            ['context' => 'Seller 1 - Email address: [___]',    'suggested_key' => 'contact.full_names'],
            ['context' => 'Seller 1 - ID / Passport number: [___]', 'suggested_key' => 'contact.full_names'],
        ]);

        $this->assertSame('contact.address',   $out[0]['suggested_key']);
        $this->assertSame('contact.phone',     $out[1]['suggested_key']);
        $this->assertSame('contact.email',     $out[2]['suggested_key']);
        $this->assertSame('contact.id_number', $out[3]['suggested_key']);
    }

    /** DocxParser shape: context_before + suggested_label. Same refinement. */
    public function test_docx_context_before_shape_is_refined(): void
    {
        $out = $this->refine([
            ['context_before' => 'Seller 1 - Physical address:', 'suggested_label' => '', 'suggested_key' => 'contact.full_names'],
        ]);
        $this->assertSame('contact.address', $out[0]['suggested_key']);
    }

    /** Price "in words" → the words variable; a plain price stays a price. */
    public function test_price_in_words_refined(): void
    {
        $out = $this->refine([
            ['context' => 'Asking price in words: [___]', 'suggested_key' => 'custom.asking_price_in_words'],
            ['context' => 'Asking price (Rand): [___]',   'suggested_key' => 'property.price'],
        ]);
        $this->assertSame('property.price_in_words', $out[0]['suggested_key']);
        $this->assertSame('property.price',          $out[1]['suggested_key'], 'a specific price binding is untouched');
    }

    /** THE GUARD: an already-specific binding is never overridden. */
    public function test_specific_bindings_untouched(): void
    {
        $out = $this->refine([
            ['context' => 'Property physical address: [___]', 'suggested_key' => 'property.address'],
            ['context' => 'Seller address: [___]',            'suggested_key' => 'contact.address'],
        ]);
        $this->assertSame('property.address', $out[0]['suggested_key']);
        $this->assertSame('contact.address',  $out[1]['suggested_key']);
    }

    /** A name marker stays the name; an unknown label is left for the vet. */
    public function test_name_and_unknown_left_alone(): void
    {
        $out = $this->refine([
            ['context' => 'Seller 1 - Full name and surname: [___]', 'suggested_key' => 'contact.full_names'],
            ['context' => 'Marital status: [___]',                   'suggested_key' => 'custom.marital_status'],
        ]);
        $this->assertSame('contact.full_names',    $out[0]['suggested_key']);
        $this->assertSame('custom.marital_status', $out[1]['suggested_key']);
    }
}
