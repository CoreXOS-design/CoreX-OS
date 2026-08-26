<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Services\Docuperfect\RoleBlockNormalizer;
use Tests\TestCase;

/**
 * P1-0 RED → P1-1 fix.
 *
 * The contract engine read the role OUT OF THE FIELD NAME (`seller_1_name`). The CDS
 * importer never wrote names that way and never did: it writes
 * `data-field="contact.first_name"` with the role in a SIBLING attribute,
 * `data-contact-type="Seller"`. So `parseFieldName()` returned null for every field the
 * importer produced, nothing was ever stamped, and every template in every database
 * rendered through the legacy clustering fallback.
 *
 * The verdict run on the REAL exclusive-authority-to-sell-v10.docx proved it end to end:
 * 0 role-anchored keys, 0 role blocks stamped, and the knife-edge legacy-clustering line
 * fired at render.
 *
 * These tests assert the normalizer now stamps the contract on the naming the importer
 * ACTUALLY emits — and that the original `seller_1_name` convention still works.
 */
final class RoleBlockNormalizerCdsNamingTest extends TestCase
{
    private function normalize(string $html): string
    {
        return app(RoleBlockNormalizer::class)->normalize($html);
    }

    /** THE FIX: the exact shape DocumentTemplateGenerator writes into tagged_html. */
    public function test_cds_pillar_naming_with_contact_type_is_stamped(): void
    {
        $html = <<<'HTML'
<div class="corex-document-wrapper">
  <p>Seller: <span class="field" data-field="contact.first_name+last_name" data-contact-type="Seller" data-label="Full Name">x</span></p>
  <p>ID No: <span class="field" data-field="contact.id_number" data-contact-type="Seller" data-label="ID Number">x</span></p>
  <p>Address: <span class="field" data-field="contact.address" data-contact-type="Seller" data-label="Address">x</span></p>
  <p>Email: <span class="field" data-field="contact.email" data-contact-type="Seller" data-label="Email">x</span></p>
</div>
HTML;

        $out = $this->normalize($html);

        $this->assertStringContainsString('data-role-block="seller"', $out);
        $this->assertSame(4, substr_count($out, 'data-role-block='), 'every party field s block must be stamped');
    }

    /** The segment hints must come through from the column half of the field name. */
    public function test_segments_are_derived_from_the_source_column(): void
    {
        $html = <<<'HTML'
<div>
  <p>Name: <span class="field" data-field="contact.first_name+last_name" data-contact-type="Seller">x</span></p>
  <p>Address: <span class="field" data-field="contact.address" data-contact-type="Seller">x</span></p>
  <p>Phone: <span class="field" data-field="contact.phone" data-contact-type="Seller">x</span></p>
</div>
HTML;

        $out = $this->normalize($html);

        $this->assertStringContainsString('data-role-block-segment="identity"', $out);
        $this->assertStringContainsString('data-role-block-segment="address"', $out);
        $this->assertStringContainsString('data-role-block-segment="contact"', $out);
    }

    /** Every real party role the importer emits (Seller/Buyer/Lessor/Lessee) resolves. */
    public function test_every_real_contact_type_resolves_to_a_role(): void
    {
        foreach (['Seller' => 'seller', 'Buyer' => 'buyer', 'Lessor' => 'lessor', 'Lessee' => 'lessee'] as $ct => $role) {
            $html = '<div><p>N: <span class="field" data-field="contact.first_name" data-contact-type="' . $ct . '">x</span></p></div>';
            $this->assertStringContainsString(
                'data-role-block="' . $role . '"',
                $this->normalize($html),
                "contact type {$ct} must resolve to role {$role}"
            );
        }
    }

    /** NO REGRESSION: the original role-anchored convention still stamps exactly as before. */
    public function test_role_anchored_naming_still_works(): void
    {
        $html = <<<'HTML'
<div>
  <p>Name: <span data-field="seller_1_name">x</span></p>
  <p>Address: <span data-field="seller_1_address">x</span></p>
</div>
HTML;

        $out = $this->normalize($html);

        $this->assertStringContainsString('data-role-block="seller"', $out);
        $this->assertStringContainsString('data-role-block-segment="identity"', $out);
        $this->assertStringContainsString('data-role-block-segment="address"', $out);
    }

    /** A field with no role at all (an unassigned/manual blank) is still left alone. */
    public function test_unassigned_fields_are_not_stamped(): void
    {
        $html = '<div><p>Price: <span class="field" data-field="manual.field_7">x</span></p>'
              . '<p>Erf: <span class="field" data-field="property.erf_number">x</span></p></div>';

        $out = $this->normalize($html);

        $this->assertStringNotContainsString('data-role-block=', $out, 'a field with no party must not be stamped');
    }

    /** An unknown contact type must not invent a role. */
    public function test_unknown_contact_type_is_ignored(): void
    {
        $html = '<div><p>X: <span class="field" data-field="contact.first_name" data-contact-type="Conveyancer">x</span></p></div>';

        $this->assertStringNotContainsString('data-role-block=', $this->normalize($html));
    }

    /** Idempotent — the contract is the same after a second pass. */
    public function test_normalising_twice_is_idempotent(): void
    {
        $html = '<div><p>N: <span class="field" data-field="contact.first_name" data-contact-type="Seller">x</span></p></div>';

        $once  = $this->normalize($html);
        $twice = $this->normalize($once);

        $this->assertSame($once, $twice);
    }

    /** Mixed roles in one document: each block gets its own role, no bleed. */
    public function test_two_roles_in_one_document_do_not_bleed(): void
    {
        $html = <<<'HTML'
<div>
  <p>Seller: <span class="field" data-field="contact.first_name" data-contact-type="Seller">x</span></p>
  <p>Buyer: <span class="field" data-field="contact.first_name" data-contact-type="Buyer">x</span></p>
</div>
HTML;

        $out = $this->normalize($html);

        $this->assertStringContainsString('data-role-block="seller"', $out);
        $this->assertStringContainsString('data-role-block="buyer"', $out);
    }
}
