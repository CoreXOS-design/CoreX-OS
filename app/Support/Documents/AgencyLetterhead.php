<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Models\Agency;
use App\Models\Branch;
use App\Support\Docuperfect\DocumentAgencyContext;

/**
 * THE letterhead every document CoreX produces for an agency carries — one resolver, one component pair
 * (<x-agency-letterhead>, <x-agency-letterhead-footer>), no per-document copies.
 *
 *   header  logo left; name, address, tel, e-mail, website and VAT line right
 *   footer  "<legal name> | Reg No <reg> | PPRA Reg <ppra>"
 *
 * Every value comes from the agency's own settings (a branch's own value wins where the branch has one). A blank value
 * prints NOTHING — never "Vat:" with nothing after it. The website is `agencies.document_website`, never `website_url`
 * (that one drives the public-listing redirect and must not leak onto documents). Spec: .ai/specs/agency-letterhead.md
 */
final class AgencyLetterhead
{
    /**
     * @param  list<string>  $addressLines
     * @param  list<string>  $phoneLines
     */
    private function __construct(
        public readonly string $name,
        public readonly array $addressLines,
        public readonly array $phoneLines,
        public readonly string $email,
        public readonly string $website,
        public readonly string $vatLine,
        public readonly string $footerLine,
        public readonly ?string $logoDataUri,
    ) {
    }

    /** The letterhead of an agency (and optionally one of its branches); null when there is no agency to name. */
    public static function for(?Agency $agency, ?Branch $branch = null): ?self
    {
        if ($agency === null) {
            return null;
        }
        if ($branch !== null && (int) $branch->agency_id !== (int) $agency->id) {
            $branch = null; // never let another agency's branch speak for this one
        }

        $pick = static function (string $field) use ($agency, $branch): string {
            if ($branch !== null && trim((string) ($branch->{$field} ?? '')) !== '') {
                return trim((string) $branch->{$field});
            }

            return trim((string) ($agency->{$field} ?? ''));
        };

        $name = $pick('trading_name') ?: trim((string) $agency->name);
        $legal = trim((string) ($agency->legal_name ?? '')) ?: $name;

        $footer = [$legal];
        if (($reg = $pick('reg_no')) !== '') {
            $footer[] = 'Reg No ' . $reg;
        }
        if (($ppra = $pick('ppra_number')) !== '') {
            $footer[] = 'PPRA Reg ' . $ppra;
        }

        $phones = [];
        foreach ([['phone', 'phone_label'], ['phone_secondary', 'phone_secondary_label']] as [$numberField, $labelField]) {
            if (($number = $pick($numberField)) !== '') {
                $label = rtrim($pick($labelField), ': ') ?: 'Tel';
                $phones[] = $label . ': ' . $number;
            }
        }

        $vat = $pick('vat_no');

        return new self(
            name: $name,
            addressLines: array_values(array_filter(array_map('trim', preg_split('/\R/', $pick('address')) ?: []), fn ($l) => $l !== '')),
            phoneLines: $phones,
            email: $pick('email'),
            website: trim((string) ($agency->document_website ?? '')),
            vatLine: $vat !== '' ? 'VAT No: ' . $vat : '',
            footerLine: implode(' | ', array_filter($footer, fn ($p) => $p !== '')),
            logoDataUri: self::logoDataUri($branch?->logo_path ?: null) ?? self::logoDataUri($agency->logo_path ?: null),
        );
    }

    public static function forAgencyId(int $agencyId, ?int $branchId = null): ?self
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);
        $branch = $branchId !== null ? Branch::withoutGlobalScopes()->find($branchId) : null;

        return self::for($agency, $branch);
    }

    /** The document's own agency (DocumentAgencyContext), else the signed-in user's. No fallback agency. */
    public static function current(): ?self
    {
        [$agency, $branch] = DocumentAgencyContext::resolve();

        return self::for($agency, $branch);
    }

    /** A logo that is not actually on disk yields null — never a broken-image box on a printed document. */
    private static function logoDataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $abs = storage_path('app/public/' . ltrim($path, '/'));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'][strtolower(pathinfo($abs, PATHINFO_EXTENSION))] ?? null;
        if ($mime === null || ! is_file($abs)) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($abs));
    }
}
