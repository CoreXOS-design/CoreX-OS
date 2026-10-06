<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\Platform\PlatformCompany;
use App\Models\PlatformEsign\Document;

/**
 * THE single adapter between the Subscription Agreement and "who RR Technologies is" (letterhead + fixed party details),
 * reading the platform company record (Admin → Platform Company Profile; spec .ai/specs/platform-company-profile.md).
 * It feeds the running letterhead (screen + PDF), the mandate's fixed beneficiary lines and the RR countersign default.
 * It does NOT feed the legal wording: clause B25 prints the company details exactly as written in the pinned version.
 *
 * The contract uses a compact running header on every page (logo + three lines), composed here from the company record's
 * fields, rather than the company page's tall full letterhead block — a full block on every page would eat the page budget.
 *
 * PINNING (spec .ai/specs/platform-company-profile.md §7a): `AgreementCompany::for($doc)` reads the company details the document
 * was SENT with (documents.company_snapshot), so a later edit on the company page never alters a sent or signed agreement.
 * `app(AgreementCompany::class)` / `for(null)` read the live record — used for new agreements and the pagination sample.
 */
class AgreementCompany
{
    public function __construct(private ?PlatformCompany $pinned = null)
    {
    }

    /** The company as pinned on a sent document (live record when none is pinned, or for no document). */
    public static function for(?Document $doc): static
    {
        $snap = $doc?->company_snapshot;

        return new static(is_array($snap) && $snap ? PlatformCompany::fromSnapshot($snap) : null);
    }

    private function c(): PlatformCompany
    {
        return $this->pinned ?? PlatformCompany::current();
    }

    /** First general email — the "questions? write to" address. */
    public function email(): string
    {
        return trim((string) $this->c()->email_general);
    }

    /** First website, for footers. */
    public function website(): string
    {
        return $this->c()->websiteList()[0] ?? '';
    }

    /** Standard email signature + footer inputs for the agreement emails. */
    public function company(): PlatformCompany
    {
        return $this->c();
    }

    /**
     * Letterhead logo box in PDF points — the platform-wide rule (PlatformCompany::logoBoxPt): fixed 45pt height (never upscaled past the image's
     * own size), width from the logo's shape, capped at 45% of the header.
     *
     * @return array{w:float,h:float}
     */
    public function logoBoxPt(?float $height = null, ?float $maxWidth = null): array
    {
        return $this->c()->logoBoxPt($height, $maxWidth);
    }

    /** Logo size on screen in px: the same rule (PlatformCompany::logoSizePx). @return array{w:int,h:int} */
    public function logoSizePx(float $headerWidthPx = 760.0): array
    {
        return $this->c()->logoSizePx($headerWidthPx);
    }

    public function legalName(): string
    {
        return trim((string) $this->c()->legal_name);
    }

    /** Brand text beside the logo. */
    public function brand(): string
    {
        return trim((string) $this->c()->trading_name) ?: $this->legalName();
    }

    /** Beneficiary address printed on the Netcash mandate. */
    public function beneficiaryAddress(): string
    {
        return implode(', ', $this->c()->addressLines());
    }

    /** @return array{name:string,address:string,contact:string} the three letterhead lines under the brand mark */
    public function letterhead(): array
    {
        $c = $this->c();
        $phones = array_map(fn ($p) => $p['number'], array_filter($c->phoneList(), fn ($p) => stripos((string) $p['label'], 'support') === false));
        $contact = array_values(array_filter([...$phones, $c->websiteList()[0] ?? '']));

        return [
            'name' => $this->legalName(),
            'address' => implode(', ', $c->addressLines()),
            'contact' => implode(' · ', $contact),
        ];
    }

    /** The company logo as a self-contained data URI (PDF letterhead). */
    public function logoDataUri(): string
    {
        return $this->c()->logoDataUri();
    }

    /** The company logo URL (screen sheets). */
    public function logoUrl(): string
    {
        return $this->c()->logoUrl();
    }
}
