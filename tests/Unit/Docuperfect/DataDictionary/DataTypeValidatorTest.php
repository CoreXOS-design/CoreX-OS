<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\DataDictionary;

use App\Support\Docuperfect\DataDictionary\DataType;
use App\Support\Docuperfect\DataDictionary\DateOrdering;
use PHPUnit\Framework\TestCase;

/**
 * WS0 GATE — every Data Dictionary validator, across the input space (BUILD_STANDARD §5):
 * happy path, optional-empty, whitespace, and malformed-rejected per type.
 * Pure unit test (no DB), per the HumanDiffTest convention.
 */
final class DataTypeValidatorTest extends TestCase
{
    /** Optional-empty must be accepted by EVERY type (requiredness is a Field concern). */
    public function test_every_type_accepts_empty_as_valid(): void
    {
        foreach (DataType::cases() as $type) {
            $this->assertTrue($type->validate(null)->valid, "{$type->value} should accept null");
            $this->assertTrue($type->validate('')->valid, "{$type->value} should accept ''");
            $this->assertTrue($type->validate('   ')->valid, "{$type->value} should accept whitespace-only");
        }
    }

    // ── SA ID (reuses App\Rules\SouthAfricanIdNumber Luhn) ────────────────────

    public function test_sa_id_accepts_valid_checksummed_ids(): void
    {
        foreach (['8001015009087', '9001010001088', '8801235111088'] as $id) {
            $this->assertTrue(DataType::SaId->validate($id)->valid, "{$id} should be valid");
        }
    }

    public function test_sa_id_accepts_spaced_input_and_normalises(): void
    {
        $result = DataType::SaId->validate('800101 5009 087');
        $this->assertTrue($result->valid);
        $this->assertSame('8001015009087', $result->normalised);
    }

    public function test_sa_id_rejects_bad_checksum_wrong_length_and_nondigits(): void
    {
        $this->assertFalse(DataType::SaId->validate('8001015009086')->valid); // check digit flipped
        $this->assertFalse(DataType::SaId->validate('123')->valid);            // too short
        $this->assertFalse(DataType::SaId->validate('80010150090XY')->valid);  // non-digits
        $this->assertFalse(DataType::SaId->validate('8013015009087')->valid);  // month 13 invalid DOB
    }

    // ── ZAR money ─────────────────────────────────────────────────────────────

    public function test_money_accepts_various_zar_formats_and_normalises_to_float(): void
    {
        $this->assertSame(950000.0, DataType::ZarMoney->validate('R 950,000')->normalised);
        $this->assertSame(1250000.5, DataType::ZarMoney->validate('R 1,250,000.50')->normalised);
        $this->assertSame(1250000.0, DataType::ZarMoney->validate('1250000')->normalised);
    }

    public function test_money_rejects_negative_and_garbage(): void
    {
        $this->assertFalse(DataType::ZarMoney->validate('-5')->valid);
        $this->assertFalse(DataType::ZarMoney->validate('abc')->valid);
        $this->assertFalse(DataType::ZarMoney->validate('R')->valid);
    }

    // ── PPRA / FFC ────────────────────────────────────────────────────────────

    public function test_ppra_and_ffc_accept_real_references_reject_garbage(): void
    {
        $this->assertTrue(DataType::PpraNo->validate('PPRA-2019/12345')->valid);
        $this->assertTrue(DataType::FfcNo->validate('FFC 2024 00123')->valid);
        $this->assertFalse(DataType::PpraNo->validate('abc')->valid);   // no digit
        $this->assertFalse(DataType::PpraNo->validate('12')->valid);    // too short
        $this->assertFalse(DataType::PpraNo->validate('12#45')->valid); // stray symbol
    }

    // ── Dates (single-field validity + Carbon-rollover rejection) ─────────────

    public function test_date_accepts_real_dates_including_leap_day(): void
    {
        $this->assertTrue(DataType::Date->validate('2026-07-05')->valid);
        $this->assertTrue(DataType::Date->validate('05/07/2026')->valid);   // SA day-first
        $this->assertTrue(DataType::Date->validate('5 July 2026')->valid);
        $this->assertTrue(DataType::Date->validate('2024-02-29')->valid);   // valid leap day
    }

    public function test_date_rejects_calendar_impossible_dates(): void
    {
        $this->assertFalse(DataType::Date->validate('2026-02-30')->valid);  // Carbon would roll over
        $this->assertFalse(DataType::Date->validate('2026-13-01')->valid);  // month 13
        $this->assertFalse(DataType::Date->validate('30/30/2026')->valid);
        $this->assertFalse(DataType::Date->validate('2025-02-29')->valid);  // not a leap year
    }

    public function test_date_absolute_bound_params(): void
    {
        $this->assertFalse(DataType::Date->validate('2026-01-01', ['after_date' => '2026-06-01'])->valid);
        $this->assertTrue(DataType::Date->validate('2026-07-01', ['after_date' => '2026-06-01'])->valid);
    }

    // ── Cross-field date ordering (occupation ≥ transfer) ─────────────────────

    public function test_date_ordering_holds_and_is_null_safe(): void
    {
        $this->assertTrue(DateOrdering::holds('2026-07-01', '2026-08-01'));   // transfer → occupation OK
        $this->assertFalse(DateOrdering::holds('2026-07-01', '2026-06-01'));  // occupation before transfer
        $this->assertTrue(DateOrdering::holds(null, '2026-08-01'));           // nothing to compare
        $this->assertTrue(DateOrdering::holds('2026-07-01', 'not-a-date'));   // unparseable → not violated
        $this->assertTrue(DateOrdering::strictlyBefore('2026-07-01', '2026-07-02'));
        $this->assertFalse(DateOrdering::strictlyBefore('2026-07-01', '2026-07-01'));
    }

    // ── Property codes ────────────────────────────────────────────────────────

    public function test_erf_title_unit_scheme_and_gps(): void
    {
        $this->assertTrue(DataType::ErfNumber->validate('512/3')->valid);
        $this->assertTrue(DataType::TitleDeed->validate('T12345/2019')->valid);
        $this->assertTrue(DataType::UnitNo->validate('12A')->valid);
        $this->assertTrue(DataType::SchemeName->validate('Seaview Heights')->valid);

        $this->assertTrue(DataType::Gps->validate('-30.7256, 30.4547')->valid);
        $this->assertFalse(DataType::Gps->validate('200, 0')->valid);        // lat out of range
        $this->assertFalse(DataType::Gps->validate('-30.7256')->valid);      // single value
    }

    // ── Party fields ──────────────────────────────────────────────────────────

    public function test_full_name_and_marital_status(): void
    {
        $this->assertTrue(DataType::FullName->validate("Thabo O'Brien-Naidoo")->valid);
        $this->assertFalse(DataType::FullName->validate('A')->valid);        // too short
        $this->assertFalse(DataType::FullName->validate('12345')->valid);    // digits only

        // Free text when no options declared.
        $this->assertTrue(DataType::MaritalStatus->validate('Married in community of property')->valid);
        // Enforced membership when options declared (case-insensitive).
        $opts = ['options' => ['Single', 'Married COP', 'Married ANC', 'Divorced', 'Widowed']];
        $this->assertTrue(DataType::MaritalStatus->validate('married anc', $opts)->valid);
        $this->assertFalse(DataType::MaritalStatus->validate('Engaged', $opts)->valid);
    }

    // ── Display formatting ────────────────────────────────────────────────────

    public function test_money_display_formats_as_zar(): void
    {
        $this->assertSame('R 1,250,000.00', DataType::ZarMoney->display('1250000'));
        $this->assertSame('', DataType::ZarMoney->display(''));
    }
}
