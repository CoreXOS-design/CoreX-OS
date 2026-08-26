<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\DocumentType;
use App\Services\Docuperfect\DocumentTypeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Classification is a LEGAL control, not metadata.
 *
 * `isEsignBlocked()`'s strongest layer reads the document-type slug — it is the only layer that
 * survives a rename. But nothing ever classified a template: the importer wrote
 * `document_type_id = null` on every document it created, and 17 live templates are
 * unclassified, including "Contract of Sale - Serenity Hills Eco Estate" — a deed of alienation
 * whose only protection was a name regex.
 *
 * The classifier's cardinal rule: **a mandate is not a sale.** "Exclusive Authority To Sell"
 * AUTHORISES a sale; it does not effect one, and it is lawfully e-signable. Mis-classifying it
 * as a sale would block the launch document. Mis-classifying a sale as a mandate would UNBLOCK a
 * deed of alienation. Both directions are dangerous, which is why it returns null rather than
 * guess.
 */
final class DocumentTypeClassifierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The lawful (e-signable) document types.
     *
     * These are seeded by OLD migrations, and `RefreshDatabase` loads the committed schema
     * SNAPSHOT — which carries tables, not the rows those migrations inserted. So they are
     * absent here while being present on any real environment. Create them, so this test proves
     * the classifier's behaviour rather than the test database's quirks.
     *
     * The five ALIENATION types are deliberately NOT created here: the migration under test is
     * what must guarantee those, and test_every_alienation_type_resolves_to_a_real_document_type_row
     * is what proves it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'mandate' => 'Mandate',
            'mandate_extension' => 'Mandate Extension',
            'fica' => 'FICA',
            'disclosure' => 'Disclosure',
            'lease_agreement' => 'Lease Agreement',
            'rental_agreement' => 'Rental Agreement',
            'addendum' => 'Addendum',
        ] as $slug => $label) {
            if (! DocumentType::query()->where('slug', $slug)->exists()) {
                DocumentType::query()->create(['slug' => $slug, 'label' => $label, 'is_active' => true]);
            }
        }
    }

    private function classify(string $name, ?string $body = null): ?string
    {
        return app(DocumentTypeClassifier::class)->classify($name, $body);
    }

    /** @dataProvider alienationDocuments */
    public function test_alienation_documents_classify_as_a_wet_ink_type(string $name, string $expected): void
    {
        $this->assertSame($expected, $this->classify($name));
    }

    public static function alienationDocuments(): array
    {
        return [
            ['SB 2026 OTP', 'otp'],
            ['Offer To Purchase (V13) — Enviro', 'offer_to_purchase'],
            ['Contract of Sale - Serenity Hills Eco Estate', 'sale_agreement'],   // the live hole
            ['Deed of Sale', 'deed_of_sale'],
            ['Deed of Transfer', 'deed_of_sale'],
            ['Deed of Alienation', 'deed_of_alienation'],
            ['Sale Agreement 2026', 'sale_agreement'],
            ['Agreement of Sale — Unit 4', 'sale_agreement'],
            ['Purchase Agreement', 'sale_agreement'],
            ['Koopkontrak', 'sale_agreement'],
            // An addendum to a deed of alienation is PART OF the alienation contract, so it is
            // wet-ink too. The classifier tests the OTP patterns before `addendum` on purpose —
            // classifying "SB 2026 OTP Addendum" as a plain `addendum` would leave it e-signable.
            ['SB 2026 OTP Addendum', 'otp'],
        ];
    }

    /**
     * THE CARDINAL RULE. A mandate is not a sale. Every one of these contains sale words, and
     * every one of them is lawfully e-signable — the EATS is the launch document.
     *
     * @dataProvider mandatesAndOtherLawfulDocuments
     */
    public function test_a_mandate_is_never_classified_as_a_sale(string $name, ?string $expected): void
    {
        $slug = $this->classify($name);

        $this->assertSame($expected, $slug);
        $this->assertNotContains(
            $slug,
            ['otp', 'offer_to_purchase', 'sale_agreement', 'deed_of_sale', 'deed_of_alienation'],
            "'{$name}' is NOT an alienation document — classifying it as one would block it from e-signing"
        );
    }

    public static function mandatesAndOtherLawfulDocuments(): array
    {
        return [
            'the launch document' => ['Exclusive Authority To Sell (V10)', 'mandate'],
            'sole mandate'        => ['Sole Mandate — Shelly Beach', 'mandate'],
            'dual mandate'        => ['SB 2026 Dual Mandate', 'mandate'],
            'mandate extension'   => ['Mandate Extension', 'mandate_extension'],
            'FICA'                => ['FICA Natural Person (V8)', 'fica'],
            'disclosure'          => ['Seller Mandatory Disclosure (V7)', 'disclosure'],
            'lease'               => ['Lease Agreement - Popi (V8)', 'lease_agreement'],
            'letting mandate'     => ['Letting Mandate (V5)', 'mandate'],
        ];
    }

    /** It refuses to guess. Null is safe — the name regex still guards the document. */
    public function test_it_returns_null_rather_than_guess(): void
    {
        $this->assertNull($this->classify('Enviro Document V13'));
        $this->assertNull($this->classify('Photoshop Workflow'));
        $this->assertNull($this->classify('Untitled'));
    }

    /**
     * It will classify a SALE on its body text when the name says nothing — that only ever
     * TIGHTENS the legal block. It will not classify anything else on content, because a
     * mandate that merely mentions a purchase price is still a mandate.
     */
    public function test_it_will_catch_a_sale_hiding_behind_a_meaningless_name(): void
    {
        $this->assertSame(
            'offer_to_purchase',
            $this->classify('Enviro Document V13', 'THE PURCHASER HEREBY MAKES AN OFFER TO PURCHASE THE PROPERTY')
        );
    }

    public function test_it_does_not_classify_a_mandate_from_body_text_alone(): void
    {
        // Body mentions a purchase price, but the document is a mandate. Content must not
        // promote it to anything — the name said nothing, so the answer is null.
        $this->assertNull(
            $this->classify('Untitled Document', 'The gross purchase price shall be R 1 250 000')
        );
    }

    /**
     * EVERY ALIENATION TYPE MUST RESOLVE TO A REAL ROW — this is the one that has teeth.
     *
     * `otp`, `sale_agreement`, `deed_of_sale` and `deed_of_alienation` existed on live ONLY
     * because someone typed them in: they appear in no migration and no seeder, so on any
     * environment built from the code (migrate:fresh, the demo, a new agency, this test
     * database) the document type a deed of alienation needs DID NOT EXIST — and a sale that
     * cannot be classified is a sale protected only by its name. AT-162, on a legal control.
     *
     * The migration now creates them, so this test is what stops that regressing.
     *
     * A non-alienation slug that has no row (e.g. mandate_extension on some environments) is
     * harmless: it resolves to null, the template stays unclassified, and it is lawfully
     * e-signable anyway. Only the wet-ink types are load-bearing.
     */
    public function test_every_alienation_type_resolves_to_a_real_document_type_row(): void
    {
        foreach (self::alienationDocuments() as [$name, $expectedSlug]) {
            $id = app(DocumentTypeClassifier::class)->classifyToId($name);

            $this->assertNotNull(
                $id,
                "'{$name}' classifies as '{$expectedSlug}' but no document_types row has that slug — "
                . 'the legal block would fall back to the name regex and a rename would launder it'
            );
            $this->assertSame($expectedSlug, DocumentType::query()->whereKey($id)->value('slug'));
        }
    }

    /** A lawful document must never resolve to a wet-ink type — that would block the launch document. */
    public function test_a_lawful_document_never_resolves_to_an_alienation_type(): void
    {
        $resolved = 0;

        foreach (self::mandatesAndOtherLawfulDocuments() as [$name, $_expected]) {
            $id = app(DocumentTypeClassifier::class)->classifyToId($name);
            if ($id === null) {
                continue;   // unclassified is safe: it stays e-signable, which it lawfully is
            }

            $resolved++;
            $this->assertNotContains(
                DocumentType::query()->whereKey($id)->value('slug'),
                ['otp', 'offer_to_purchase', 'sale_agreement', 'deed_of_sale', 'deed_of_alienation'],
                "'{$name}' must not be classified as an alienation document"
            );
        }

        // Guard the guard: if nothing resolved, the loop above asserted nothing and this test
        // would pass while proving nothing at all.
        $this->assertGreaterThan(0, $resolved, 'no lawful document resolved — the test proved nothing');
    }

    /** The id resolver returns a real row id, and null for the unclassifiable. */
    public function test_classify_to_id_resolves_a_real_row(): void
    {
        $svc = app(DocumentTypeClassifier::class);

        $id = $svc->classifyToId('Contract of Sale - Serenity Hills');
        $this->assertNotNull($id);
        $this->assertSame('sale_agreement', DocumentType::query()->whereKey($id)->value('slug'));

        $this->assertNull($svc->classifyToId('Enviro Document V13'));
    }
}
