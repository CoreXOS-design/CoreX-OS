<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Services\Docuperfect\CdsBindingProjector;
use App\Services\Docuperfect\RoleBlockDetectionService;
use App\Services\Docuperfect\RoleBlockNormalizer;
use Tests\TestCase;

/**
 * THE BRIDGE — why the data-role-block contract never took, and the proof that it now does.
 *
 * The engine reads a field's ROLE out of the HTML. The CDS builder never put it there: it
 * writes `data-field-name="contact.full_names"` (not `data-field`) and keeps the binding —
 * which contact type the field belongs to — in a separate `mappings` JSON keyed by
 * `data-tag-id`. The normalizer is handed only HTML.
 *
 * So `//*[@data-field]` matched ZERO nodes on every CDS template ever imported, no field was
 * role-bearing, nothing was stamped, and every multi-party mandate fell through to legacy
 * clustering. Contract coverage was zero in every database since the engine shipped — and the
 * one-time backfill was a no-op, because there was never anything for it to find.
 *
 * The engine was never broken. Its input was never produced.
 */
final class CdsContractBridgeTest extends TestCase
{
    /** Exactly what the CDS builder writes — attribute names and all. */
    private function builderHtml(): string
    {
        return <<<'HTML'
<div class="corex-clause">
  <p>I / We
    <span class="doc-tag" data-field-name="contact.full_names" data-field-label="Owner Name(s)"
          data-tag-id="tag-owner">[Owner Name(s)]</span>
    the undersigned, being the registered owner of
    <span class="doc-tag" data-field-name="property.street" data-field-label="Street"
          data-tag-id="tag-street">[Street]</span>
  </p>
</div>
HTML;
    }

    /** @return array<string,array<string,mixed>> the curated bindings, as they live in the draft */
    private function mappings(): array
    {
        return [
            'tag-owner'  => ['sourceContactType' => 'Seller', 'mappingType' => 'named_field'],
            'tag-street' => ['sourceType' => 'property'],   // property field — party-less, correctly
        ];
    }

    // ── the defect, reproduced ───────────────────────────────────────────

    /** The builder's own output, fed straight to the normalizer, stamps NOTHING. */
    public function test_the_builders_markup_alone_stamps_no_contract(): void
    {
        $out = app(RoleBlockNormalizer::class)->normalize($this->builderHtml());

        $this->assertStringNotContainsString('data-role-block', $out,
            'This is the six-week defect: no role in the markup, so nothing to stamp.');
    }

    // ── the bridge ───────────────────────────────────────────────────────

    /** Projecting the binding onto the markup makes the field role-bearing. */
    public function test_projection_publishes_the_role_the_mappings_were_hiding(): void
    {
        $projected = app(CdsBindingProjector::class)->project($this->builderHtml(), $this->mappings());

        // The role — which only ever existed in the mappings — is now IN the document.
        $this->assertStringContainsString('data-contact-type="Seller"', $projected);
        // ...and the name is mirrored into the attribute every legacy selector looks for.
        $this->assertStringContainsString('data-field="contact.full_names"', $projected);

        // A property field is party-less and must STAY party-less — no role invented.
        $this->assertStringNotContainsString('data-contact-type="property"', $projected);
    }

    /** ...and with the role visible, the engine stamps the contract. THE knife-edge. */
    public function test_the_contract_stamps_once_the_binding_is_projected(): void
    {
        $projected = app(CdsBindingProjector::class)->project($this->builderHtml(), $this->mappings());
        $out = app(RoleBlockNormalizer::class)->normalize($projected);

        $this->assertStringContainsString('data-role-block="seller"', $out,
            'The contract must stamp the seller block once the role is visible.');
        $this->assertStringContainsString('data-role-block-segment="identity"', $out);
    }

    // ── the detector reads BOTH shapes ───────────────────────────────────

    public function test_the_detector_reads_the_cds_attribute_name(): void
    {
        $dom = new \DOMDocument();
        $dom->loadHTML('<span data-field-name="contact.full_names" data-contact-type="Seller"></span>');
        $el = $dom->getElementsByTagName('span')->item(0);

        $parsed = app(RoleBlockDetectionService::class)->resolveFieldElement($el);

        $this->assertSame('seller', $parsed['role_base']);
        $this->assertSame('full_names', $parsed['sub_name']);
    }

    /** The legacy role-anchored shape must keep working — old documents still render. */
    public function test_the_legacy_data_field_shape_still_resolves(): void
    {
        $dom = new \DOMDocument();
        $dom->loadHTML('<span data-field="seller_1_name"></span>');
        $el = $dom->getElementsByTagName('span')->item(0);

        $parsed = app(RoleBlockDetectionService::class)->resolveFieldElement($el);

        $this->assertSame('seller', $parsed['role_base']);
        $this->assertSame(1, $parsed['instance_index']);
    }

    /** A field with no binding stays party-less: the projector invents nothing. */
    public function test_an_unbound_field_is_left_alone(): void
    {
        $html = '<p><span data-field-name="contact.full_names" data-tag-id="tag-x">[X]</span></p>';

        $projected = app(CdsBindingProjector::class)->project($html, ['tag-x' => ['mappingType' => 'manual']]);

        $this->assertStringNotContainsString('data-contact-type', $projected,
            'No binding means no role — never a guessed one.');
        $this->assertStringNotContainsString('data-role-block',
            app(RoleBlockNormalizer::class)->normalize($projected));
    }
}
