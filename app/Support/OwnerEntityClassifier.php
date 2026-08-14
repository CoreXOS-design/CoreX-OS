<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Classifies a scraped/deeds-office property OWNER as a natural person or a
 * juristic ENTITY (company / CC / trust / body corporate / municipality).
 *
 * WHY THIS EXISTS — the bug it fixes (ground truth, CMA ref 0701):
 * Owner "SAUNDERS BRANDON HENRY", ID 721135198089 — a natural person — was
 * flagged a COMPANY. The old rule classified "company" whenever an owner
 * id_number was present but failed strict SA-ID validation
 * (`!SouthAfricanIdNumber::isValid()`): 721135198089 is a short/invalid SA ID
 * (12 digits), so it tripped the company branch and the owner was dropped. The
 * fix keys on the STRONGEST available signal, in the priority order below —
 * the presence of an SA ID FIELD means natural person, and a name is NEVER
 * treated as a company just because it is all-caps, comma-less, or multi-token.
 *
 * Priority order (strongest signal first):
 *   1. Explicit COMPANY registration signal → ENTITY:
 *        a. id_type === 'company_reg' (the extension's CIPC-shape flag), or
 *        b. a CIPC-format registration number  YYYY/NNNNNN/NN.
 *   2. An SA ID field is populated → NATURAL PERSON. A company never carries an
 *      SA ID; a present-but-invalid/short SA ID (0701's 721135198089) is still
 *      a person's ID, not a company. (id_type === 'sa_id', or a bare-digit
 *      6–13 char id number.)
 *   3. No decisive ID — fall back to owner-NAME keywords for entities
 *      ((PTY) LTD, LTD, CC, BK, TRUST, INC, ESTATE LATE, BOEDEL, Beleggings, …).
 *   4. Default → NATURAL PERSON. Never a company from all-caps / no comma /
 *      multiple name tokens alone.
 *
 * Company registration is checked before the SA-ID rule so a genuine company
 * (flagged or CIPC-formatted) is never swallowed; the SA-ID rule is checked
 * before name keywords so a real person (0701) is never re-classified by their
 * name. Name keywords therefore only decide the no-ID case.
 */
final class OwnerEntityClassifier
{
    /**
     * Juristic-entity name markers, applied ONLY when the owner has no usable ID
     * (step 3) — so a real person who has an ID is never re-classified by their
     * name. Word-boundary, case-insensitive. "TRUST" is included (per the ground
     * truth keyword list) and can only ever decide a no-ID owner, so a person
     * with an ID named/surnamed "Trust" is unaffected. A lone "Estate" is NOT a
     * marker (it is also a common SA given name) — deceased estates are matched
     * as the two-word "Estate Late" / "Boedel" forms only.
     */
    private const COMPANY_PATTERNS = [
        '/\(PTY\)/i', '/\bPTY\b/i', '/\bLTD\b/i', '/\bLIMITED\b/i',
        '/\bEDMS\b/i', '/\bBPK\b/i', '/\bINC\b/i', '/\bNPC\b/i', '/\bSOC\b/i',
        '/\bBELEGGINGS\b/i', '/\bEIENDOMS\b/i', '/\bBOERDERY\b/i',
        '/\bBODY\s+CORPORATE\b/i', '/\bMUNICIPALITY\b/i', '/\bMUNISIPALITEIT\b/i',
        '/\bHOME\s*OWNERS?\b/i', '/\bHOA\b/',
        '/\bINVESTMENTS?\b/i', '/\bPROPERT(?:Y|IES)\b/i', '/\bHOLDINGS?\b/i',
        '/\bENTERPRISES?\b/i', '/\bDEVELOPMENTS?\b/i', '/\bTRADING\b/i',
        '/\bTRUST\b/i', '/\bTESTAMENT\w*\b/i', '/\bINTER\s+VIVOS\b/i',
        '/\bESTATE\s+LATE\b/i', '/\bBOEDEL\b/i', '/\bWYLE\b/i',
        '/\sCC$/', '/\sBK$/',
    ];

    /** True when the owner should be captured as a juristic ENTITY, not a person. */
    public static function isEntity(?string $name, ?string $idType, ?string $idNumber): bool
    {
        $id = trim((string) $idNumber);

        // 1. Explicit COMPANY registration signal.
        if ($idType === 'company_reg' || self::looksLikeCipcReg($id)) {
            return true;
        }

        // 2. An SA ID field is populated → NATURAL PERSON (companies never carry
        //    one; a short/invalid SA ID is still a person's ID — the 0701 fix).
        if ($idType === 'sa_id' && $id !== '') {
            return false;
        }
        if ($id !== '' && preg_match('/^\d{6,13}$/', $id) === 1) {
            return false;
        }

        // 3. No decisive ID — owner-NAME keywords decide.
        if (self::looksLikeCompany($name)) {
            return true;
        }

        // 4. Default: natural person.
        return false;
    }

    /** Does the owner NAME carry an unambiguous company/CC/trust/estate marker? */
    public static function looksLikeCompany(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '') {
            return false;
        }
        foreach (self::COMPANY_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }
        return false;
    }

    /** CIPC registration number shape: YYYY/NNNNNN/NN (e.g. 2015/123456/07). */
    private static function looksLikeCipcReg(?string $id): bool
    {
        $id = trim((string) $id);
        return $id !== '' && preg_match('#^\d{4}/\d{6}/\d{2}$#', $id) === 1;
    }
}
