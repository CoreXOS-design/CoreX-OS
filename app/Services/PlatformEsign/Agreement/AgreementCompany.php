<?php

namespace App\Services\PlatformEsign\Agreement;

use Illuminate\Support\Facades\Blade;

/**
 * THE single adapter between the Subscription Agreement and "who RR Technologies is" (letterhead + fixed party details).
 * Interim: returns the details Johan supplied (6 Oct). When the platform company record lands (lane cc5:
 * PlatformCompany::current() / letterheadHtml()), swap the bodies below to read it — nothing else in the contract changes.
 * This adapter feeds the letterhead (screen + PDF), the mandate's fixed beneficiary lines and the RR countersign default.
 * It does NOT feed the legal wording (clause B25 prints the company details as written in the pinned version).
 */
class AgreementCompany
{
    public function legalName(): string
    {
        return 'RR Technologies (Pty) Ltd';
    }

    /** Beneficiary address printed on the Netcash mandate. */
    public function beneficiaryAddress(): string
    {
        return '3123 San Lameer, R61 Lower South Coast Road, Southbroom, Kzn, 4277';
    }

    /** @return array{name:string,address:string,contact:string} the three letterhead lines under the brand mark */
    public function letterhead(): array
    {
        return [
            'name' => $this->legalName(),
            'address' => '3123 San Lameer, Lower South Coast Road, Southbroom 4277',
            'contact' => '+27 (039) 004 0125 · +27 (0)76 618 5578 · www.corexweb.co.za',
        ];
    }

    /** The CoreX OS mark as an inline data URI (navy) for the PDF letterhead. */
    public function logoDataUri(): string
    {
        static $uri = null;
        if ($uri === null) {
            $svg = Blade::render('<x-application-logo fill="#0b2a4a" />');
            $svg = preg_replace('/^.*?(<svg)/s', '$1', $svg);
            $uri = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }

        return $uri;
    }
}
