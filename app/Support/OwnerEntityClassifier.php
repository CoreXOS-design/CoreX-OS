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
    /**
     * STRONG markers — unambiguous company/CC/close-corp forms that never appear
     * in a natural person's name. Checked BEFORE the SA-ID rule so a company
     * whose CIPC reg was stuffed into the id field as bare digits (e.g.
     * "1502 BEAUMONT PROP CC" / reg 201001792823) is still typed as an ENTITY,
     * not a fake person — while a real person with a short/invalid SA ID and an
     * ordinary name (SAUNDERS/0701) stays a person.
     */
    private const STRONG_COMPANY_PATTERNS = [
        '/\(PTY\)/i', '/\bPTY\b/i', '/\bLTD\b/i', '/\bLIMITED\b/i',
        '/\bEDMS\b/i', '/\bBPK\b/i', '/\bINC\b/i', '/\bNPC\b/i', '/\bSOC\b/i',
        '/\bBELEGGINGS\b/i', '/\bEIENDOMS\b/i', '/\bBOERDERY\b/i',
        '/\bBODY\s+CORPORATE\b/i', '/\bMUNICIPALITY\b/i', '/\bMUNISIPALITEIT\b/i',
        '/\bHOME\s*OWNERS?\b/i', '/\bHOA\b/',
        '/\bINVESTMENTS?\b/i', '/\bPROPERT(?:Y|IES)\b/i', '/\bHOLDINGS?\b/i',
        '/\bENTERPRISES?\b/i', '/\bDEVELOPMENTS?\b/i', '/\bTRADING\b/i',
        '/\sCC$/', '/\sBK$/',
    ];

    /**
     * WEAK markers — also SA given names / surnames (a lone "Trust", "Estate"),
     * and the CMA surname-first layout makes trailing tokens risky. These only
     * decide a NO-ID owner, so a real person who HAS an SA ID is never re-typed
     * by their name.
     */
    private const WEAK_COMPANY_PATTERNS = [
        '/\bTRUST\b/i', '/\bTESTAMENT\w*\b/i', '/\bINTER\s+VIVOS\b/i',
        '/\bESTATE\s+LATE\b/i', '/\bBOEDEL\b/i', '/\bWYLE\b/i',
    ];

    /** True when the owner should be captured as a juristic ENTITY, not a person. */
    public static function isEntity(?string $name, ?string $idType, ?string $idNumber): bool
    {
        $id = trim((string) $idNumber);

        // 1. Explicit COMPANY / TRUST registration signal. A trust registration
        //    number (e.g. "IT 1203/91") is never an SA ID (fails
        //    SouthAfricanIdNumber::isValid() outright — not 13 digits) and never a
        //    natural person's identifier; routing it through here means a trust
        //    owner is recognised from its ID shape alone even when its name carries
        //    no "TRUST" marker (step 2 below already catches the name-marker case).
        if ($idType === 'company_reg' || $idType === 'trust_reg' || self::looksLikeCipcReg($id) || self::looksLikeTrustReg($id)) {
            return true;
        }

        // 2. Unambiguous company NAME marker → ENTITY, even when a bare-digit reg
        //    sits in the id field ("1502 BEAUMONT PROP CC" / 201001792823). These
        //    markers never appear in a natural person's name, so this safely
        //    OVERRIDES the SA-ID rule below.
        if (self::matchesAny($name, self::STRONG_COMPANY_PATTERNS)) {
            return true;
        }

        // 3. An SA ID field is populated → NATURAL PERSON (companies never carry
        //    one; a short/invalid SA ID is still a person's ID — the 0701 fix).
        if ($idType === 'sa_id' && $id !== '') {
            return false;
        }
        if ($id !== '' && preg_match('/^\d{6,13}$/', $id) === 1) {
            return false;
        }

        // 4. No decisive ID — softer name markers (trusts/estates) decide too.
        if (self::looksLikeCompany($name)) {
            return true;
        }

        // 5. Default: natural person.
        return false;
    }

    /** Does the owner NAME carry ANY company/CC/trust/estate marker (strong or weak)? */
    public static function looksLikeCompany(?string $name): bool
    {
        return self::matchesAny($name, self::STRONG_COMPANY_PATTERNS)
            || self::matchesAny($name, self::WEAK_COMPANY_PATTERNS);
    }

    /** @param array<string> $patterns */
    private static function matchesAny(?string $name, array $patterns): bool
    {
        $name = trim((string) $name);
        if ($name === '') {
            return false;
        }
        foreach ($patterns as $pattern) {
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

    /** SA trust registration number shape: "IT" + digits + "/" + 2-4 digit year (e.g. "IT 1203/91"). */
    public static function looksLikeTrustReg(?string $id): bool
    {
        $id = trim((string) $id);
        return $id !== '' && preg_match('/^IT\s*\d+\/\d{2,4}$/i', $id) === 1;
    }
}
