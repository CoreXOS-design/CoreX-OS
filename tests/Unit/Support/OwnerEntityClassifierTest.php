<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OwnerEntityClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Owner classification (natural person vs juristic entity) for the deeds/CMA
 * scraper. Pure logic — no DB. Covers the false-positive fix (real people no
 * longer blocked as companies) AND that genuine company detection still fires.
 */
final class OwnerEntityClassifierTest extends TestCase
{
    private const VALID_SA_ID = '9001015009086'; // 13-digit, valid DOB + Luhn

    /** @return array<string, array{0:string,1:?string,2:?string,3:bool}> */
    public static function cases(): array
    {
        return [
            // ── natural persons — must NOT be flagged entity ──
            // GROUND TRUTH — CMA ref 0701. Short/invalid SA ID present → person.
            '0701 SAUNDERS BRANDON HENRY (sa_id 721135198089)' => ['SAUNDERS BRANDON HENRY', 'sa_id', '721135198089', false],
            '0701 unflagged id                                 ' => ['SAUNDERS BRANDON HENRY', null, '721135198089', false],
            // The name alone (ALL-CAPS, 3 tokens, no comma) is NOT a company signal.
            '0701 name, NO id → still natural (not a company)'   => ['SAUNDERS BRANDON HENRY', null, null, false],
            'valid SA ID → natural'                              => ['Jane Doe', 'sa_id', self::VALID_SA_ID, false],
            'foreign national, invalid SA ID → natural'          => ['Jean-Pierre Dubois', 'sa_id', '740632111088', false],
            'bare-digit id, no id_type → natural'                => ['Thabo Nkosi', null, '8202025009087', false],
            'ordinary name, no id → natural'                     => ['John Smith', null, null, false],
            'comma-form name, no id → natural'                   => ['Nkosi, Thabo', null, null, false],
            'empty id → natural'                                 => ['Thabo Nkosi', 'sa_id', '', false],
            'given name Estate, no id → natural'                 => ['Estate Mahlangu', null, null, false],

            // ── genuine entities — must be flagged entity ──
            // BUG 1: company name wins over a bare-digit reg stuffed in the id field.
            'BEAUMONT bare reg in id → entity'   => ['1502 BEAUMONT PROP CC', null, '201001792823', true],
            'BEAUMONT sa_id-flagged reg → entity' => ['1502 BEAUMONT PROP CC', 'sa_id', '201001792823', true],
            'Pty Ltd with a bare id → entity'    => ['ACME (PTY) LTD', null, '8202025009087', true],
            'company_reg flag + CIPC no'     => ['Acme', 'company_reg', '2015/123456/07', true],
            'company_reg flag beats bare id' => ['Acme', 'company_reg', '201512345607', true],
            'CIPC-format id, no flag'        => ['Acme', null, '2015/123456/07', true],
            'Pty Ltd, no id'                 => ['ACME PROPERTIES (PTY) LTD', null, null, true],
            'trailing CC, no id'             => ['XYZ TRADING CC', null, null, true],
            'family trust, no id'            => ['SMITH FAMILY TRUST', null, null, true],
            'bare TRUST, no id'              => ['SMITH TRUST', null, null, true],
            'estate late, no id'             => ['ESTATE LATE JOHN SMITH', null, null, true],
            'body corporate, no id'          => ['Blue Waters Body Corporate', null, null, true],
            'afrikaans beleggings, no id'    => ['SONOP BELEGGINGS BK', null, null, true],
            'municipality, no id'            => ['Ray Nkonyeni Municipality', null, null, true],
            'holdings, no id'                => ['Ntuli Holdings', null, null, true],
        ];
    }

    #[DataProvider('cases')]
    public function test_classifies_owner(string $name, ?string $idType, ?string $idNumber, bool $expectEntity): void
    {
        $this->assertSame(
            $expectEntity,
            OwnerEntityClassifier::isEntity($name, $idType, $idNumber),
            "[{$name}] idType=" . var_export($idType, true) . " id=" . var_export($idNumber, true)
        );
    }
}
