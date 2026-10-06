<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\Platform\PlatformCompany;

/**
 * THE single adapter between the Subscription Agreement and "who RR Technologies is" (letterhead + fixed party details),
 * reading the platform company record (Admin → Platform Company Profile; spec .ai/specs/platform-company-profile.md).
 * It feeds the running letterhead (screen + PDF), the mandate's fixed beneficiary lines and the RR countersign default.
 * It does NOT feed the legal wording: clause B25 prints the company details exactly as written in the pinned version.
 *
 * The contract uses a compact running header on every page (logo + three lines), composed here from the company record's
 * fields, rather than the company page's tall full letterhead block — a full block on every page would eat the page budget.
 */
class AgreementCompany
{
    private function c(): PlatformCompany
    {
        return PlatformCompany::current();
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
