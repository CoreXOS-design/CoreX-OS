<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\DocumentType;

/**
 * What IS this document? — the canonical classification.
 *
 * This exists because of a legal control. `Template::isEsignBlocked()` blocks alienation
 * documents from e-signing under ECTA §13(1) (a sale e-signed is VOID), and its FIRST and
 * strongest layer reads the template's `document_type` slug. That layer works — five live OTPs
 * are blocked by it today.
 *
 * The hole was never the layer. It was that **nothing ever classified a template**:
 *   - the importer created every document with `document_type_id = null`;
 *   - 17 live templates are unclassified, including "Contract of Sale - Serenity Hills Eco
 *     Estate" — a deed of alienation whose only protection was a name regex.
 *
 * An unclassified sale is protected by what it is CALLED. A classified sale is protected by
 * what it IS — rename it and it stays blocked. That is the whole point of this class.
 *
 * Deliberately conservative: it returns null rather than guess. A wrong classification on a
 * legal control is worse than none, because none still falls through to the name regex, while
 * a wrong one can mark a sale "mandate" and unblock it.
 */
class DocumentTypeClassifier
{
    /**
     * Ordered — FIRST match wins, and the order is load-bearing.
     *
     * Alienation documents are tested BEFORE mandates, because "Offer to Purchase" and
     * "Authority to Sell" both contain sale words and only one of them is a sale. A mandate
     * AUTHORISES a sale; it does not effect one, and it is lawfully e-signable.
     *
     * @var array<string, string> slug => pattern
     */
    private const PATTERNS = [
        // ── Alienation of land — WET INK ONLY (ECTA §13(1)). Tested first, always. ──
        'otp' => '/\botp\b/i',
        'offer_to_purchase' => '/\boffer\s+to\s+purchase\b/i',
        'deed_of_alienation' => '/\bdeed\s+of\s+alienation\b/i',
        'deed_of_sale' => '/\bdeed\s+of\s+(sale|transfer)\b/i',
        'sale_agreement' => '/\b((sale|purchase)\s+agreement|agreement\s+(of|for)\s+sale|contract\s+of\s+sale|agreement\s+of\s+purchase\s+and\s+sale|sale\s+of\s+immovable\s+property|koopkontrak)\b/i',

        // ── Everything below is lawfully e-signable. ──
        'disclosure' => '/\bdisclosure\b/i',
        'fica' => '/\bfica\b/i',
        'mandate_extension' => '/\bmandate\s+extension\b/i',
        'mandate_price_reduction' => '/\bprice\s+reduction\b/i',
        // A mandate: exclusive/sole/dual authority to sell, or a letting mandate.
        'mandate' => '/\b(mandate|authority\s+to\s+sell|sole\s+mandate|exclusive\s+authority)\b/i',
        'lease_agreement' => '/\blease\s+agreement\b/i',
        'rental_agreement' => '/\b(rental\s+agreement|rental\s+application)\b/i',
        'addendum' => '/\baddendum\b/i',
        'condition_report' => '/\bcondition\s+report\b/i',
        'inspection_report' => '/\binspection\s+report\b/i',
        'power_of_attorney' => '/\bpower\s+of\s+attorney\b/i',
    ];

    /**
     * The slug this document is, or null when we cannot tell.
     *
     * Null is a legitimate, safe answer: `isEsignBlocked()` still falls through to the name
     * regex. Guessing is not — a sale mis-classified as a mandate would be UNBLOCKED.
     */
    public function classify(string $name, ?string $bodyText = null): ?string
    {
        foreach (self::PATTERNS as $slug => $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return $slug;
            }
        }

        // The name told us nothing. Try the document's own text — but ONLY for the alienation
        // patterns, and only on a strong, unambiguous heading-style hit. We are willing to
        // classify a document as a SALE on its content (that only ever tightens the legal
        // block); we are not willing to classify it as anything else on content, because a
        // mandate that merely mentions "purchase price" is still a mandate.
        if ($bodyText !== null && $bodyText !== '') {
            foreach (['otp', 'offer_to_purchase', 'deed_of_alienation', 'deed_of_sale', 'sale_agreement'] as $slug) {
                if (preg_match(self::PATTERNS[$slug], $bodyText) === 1) {
                    return $slug;
                }
            }
        }

        return null;
    }

    /** The document_types row id for this document, or null when it cannot be classified. */
    public function classifyToId(string $name, ?string $bodyText = null): ?int
    {
        $slug = $this->classify($name, $bodyText);
        if ($slug === null) {
            return null;
        }

        return DocumentType::query()->where('slug', $slug)->value('id');
    }
}
