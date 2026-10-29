<?php

declare(strict_types=1);

namespace Tests\Unit\Property;

use App\Support\Property\PortalRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * .ai/specs/property-identity.md §4.1 — every raw spelling of a portal advert number found in the
 * 29 Oct audit maps to ONE canonical reference; anything that is not an advert number maps to null.
 */
final class PortalRefTest extends TestCase
{
    /** @return array<string, array{0:?string,1:?string,2:?string}> raw, hint, expected */
    public static function readable(): array
    {
        return [
            'MIC p24 row'                   => ['P24-100352744', 'p24', 'p24:100352744'],
            'MIC pp row'                    => ['PP-T1014486', 'pp', 'pp:T1014486'],
            'refs p24 (type p24)'           => ['P24-115909286', 'p24', 'p24:115909286'],
            'refs property24 (bare)'        => ['116123456', 'property24', 'p24:116123456'],
            'refs private_property'         => ['T1014486', 'private_property', 'pp:T1014486'],
            'refs private_property RR'      => ['RR4719094', 'private_property', 'pp:RR4719094'],
            'alerts'                        => ['P24-117743851', 'p24', 'p24:117743851'],
            'captures bare, site hint'      => ['100352744', 'www.property24.com', 'p24:100352744'],
            'own stock p24_ref'             => ['103977859', 'p24', 'p24:103977859'],
            'own stock pp_ref T'            => ['T5535694', 'pp', 'pp:T5535694'],
            'own stock pp_ref RR'           => ['RR4719094', 'pp', 'pp:RR4719094'],
            'prefix without hint'           => ['P24-116123456', null, 'p24:116123456'],
            'prefix lower with colon'       => ['p24:116123456', null, 'p24:116123456'],
            'prefix with space'             => ['PP T1014486', null, 'pp:T1014486'],
            'prefix wins over a wrong hint' => ['P24-116123456', 'pp', 'p24:116123456'],
            'pp letters lower-case'         => ['t1014486', 'pp', 'pp:T1014486'],
            'letters+digits without hint'   => ['T1014486', null, 'pp:T1014486'],
            'p24 glued prefix'              => ['P24116123456', null, 'p24:116123456'],
            'spreadsheet float'             => ['116123456.0', 'p24', 'p24:116123456'],
            'padded and quoted'             => ["  '116123456'\u{00A0}", 'p24', 'p24:116123456'],
            'zero width space'              => ["116\u{200B}123456", 'p24', 'p24:116123456'],
            'p24 url'                       => ['https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/5512/116123456', null, 'p24:116123456'],
            'p24 url with query'            => ['https://www.property24.com/for-sale/x/y/z/1/116123456?plId=1#top', null, 'p24:116123456'],
            'pp url'                        => ['https://www.privateproperty.co.za/for-sale/kwazulu-natal/south-coast/uvongo/12-x-road/T1014486', null, 'pp:T1014486'],
            'pp url RR lower'               => ['https://www.privateproperty.co.za/for-sale/x/rr4719094', null, 'pp:RR4719094'],
            'already canonical p24'         => ['p24:116123456', null, 'p24:116123456'],
            'already canonical pp'          => ['pp:T1014486', null, 'pp:T1014486'],
        ];
    }

    #[DataProvider('readable')]
    public function test_every_audited_spelling_maps_to_one_canonical_form(?string $raw, ?string $hint, ?string $expected): void
    {
        $this->assertSame($expected, PortalRef::normalise($raw, $hint));
    }

    #[DataProvider('readable')]
    public function test_normalising_twice_changes_nothing(?string $raw, ?string $hint, ?string $expected): void
    {
        $once = PortalRef::normalise($raw, $hint);
        $this->assertSame($once, PortalRef::normalise($once, $hint));
        $this->assertSame($once, PortalRef::normalise($once));
    }

    /** @return array<string, array{0:?string,1:?string}> */
    public static function unreadable(): array
    {
        return [
            'null'                         => [null, 'p24'],
            'empty'                        => ['', 'p24'],
            'spaces'                       => ['   ', 'p24'],
            'bare digits without a hint'   => ['116123456', null],
            'bare digits, pp hint'         => ['116123456', 'pp'],
            'bare digits, unknown hint'    => ['116123456', 'cmainfo'],
            'too short for p24'            => ['12345', 'p24'],
            'too long for p24'             => ['1234567890123', 'p24'],
            'the lane test marker'         => ['CC5-LIVE-ISSUEA-TEST-1787027314', 'p24'],
            'prospecting marker'           => ['prospecting:1505', 'p24'],
            'letters+digits under p24'     => ['T1014486', 'p24'],
            'cmainfo ref'                  => ['cmainfo:SS123/45', null],
            'unknown url'                  => ['https://example.com/123456789', null],
            'p24 url with no id'           => ['https://www.property24.com/for-sale/uvongo', null],
            'prefix with a non-advert id'  => ['P24-abc', null],
            'pp prefix with digits only'   => ['PP-1014486', null],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_a_value_that_is_not_an_advert_number_is_null(?string $raw, ?string $hint): void
    {
        $this->assertNull(PortalRef::normalise($raw, $hint));
    }

    public function test_portal_hint_is_read_from_every_string_the_codebase_uses(): void
    {
        foreach (['p24', 'P24', 'property24', 'www.property24.com', 'P24-listing'] as $h) {
            $this->assertSame('p24', PortalRef::portal($h), $h);
        }
        foreach (['pp', 'PP', 'private_property', 'www.privateproperty.co.za', 'Private Property', 'pp_ref'] as $h) {
            $this->assertSame('pp', PortalRef::portal($h), $h);
        }
        foreach ([null, '', 'cmainfo', 'deeds_capture', 'chrome_capture', 'manual_prospect_entry'] as $h) {
            $this->assertNull(PortalRef::portal($h), (string) $h);
        }
    }

    public function test_split_and_is_canonical(): void
    {
        $this->assertSame(['p24', '116123456'], PortalRef::split('p24:116123456'));
        $this->assertSame(['pp', 'T1014486'], PortalRef::split('pp:T1014486'));
        $this->assertNull(PortalRef::split('116123456'));
        $this->assertNull(PortalRef::split(null));
        $this->assertTrue(PortalRef::isCanonical('p24:116123456'));
        $this->assertFalse(PortalRef::isCanonical('P24-116123456'));
        $this->assertFalse(PortalRef::isCanonical(null));
    }

    public function test_canonical_form_fits_the_column(): void
    {
        $this->assertLessThanOrEqual(40, strlen(PortalRef::normalise('P24-123456789012', 'p24') ?? ''));
        $this->assertLessThanOrEqual(40, strlen(PortalRef::normalise('RRR1234567890', 'pp') ?? ''));
    }
}
