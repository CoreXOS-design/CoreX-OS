<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SensitiveValueMask;
use PHPUnit\Framework\TestCase;

/**
 * AT-516 - the masking rule for identity / account numbers shown in the corrected-details email and the Changes panel.
 */
final class SensitiveValueMaskTest extends TestCase
{
    public function test_same_length_shows_only_the_positions_that_changed(): void
    {
        $this->assertSame(['*5***********', '*0***********'], SensitiveValueMask::pair('8501015800083', '8001015800083'));
        $this->assertSame(['***2*****', '***7*****'], SensitiveValueMask::pair('123245678', '123745678'));
    }

    public function test_more_than_half_changed_falls_back_to_the_last_four(): void
    {
        $this->assertSame(['*****5678', '*****4321'], SensitiveValueMask::pair('123455678', '987654321'));
        // Exactly half is still "positions that changed"; one more than half is not.
        $this->assertSame(['1*3*', '9*7*'], SensitiveValueMask::pair('1234', '9274'), 'exactly half changed');
        $this->assertSame(['****', '****'], SensitiveValueMask::pair('1234', '9876'), 'a 4-character number is never shown in full');
    }

    public function test_different_lengths_show_only_the_last_four_of_each(): void
    {
        $this->assertSame(['******0001', '********0099'], SensitiveValueMask::pair('6200000001', '620000000099'));
    }

    public function test_a_blank_side_stays_blank_and_the_other_side_shows_its_last_four(): void
    {
        $this->assertSame([null, '*********0083'], SensitiveValueMask::pair(null, '8501010800083'));
        $this->assertSame(['*********0083', ''], SensitiveValueMask::pair('8501010800083', ''));
    }

    public function test_it_works_on_multibyte_values(): void
    {
        $this->assertSame(['*é**', '*e**'], SensitiveValueMask::pair('Aé12', 'Ae12'));
    }

    public function test_only_identity_and_account_fields_are_sensitive(): void
    {
        foreach (['id_number', 'spouse_id', 'bank_account_no', 'income_tax_no', 'co_applicant.id_number', 'co_applicant.income_tax_no'] as $path) {
            $this->assertTrue(SensitiveValueMask::isSensitive($path), $path);
        }
        foreach (['employer_name', 'full_name', 'bank_name', 'monthly_salary', 'co_applicant.full_name', 'co_occupants.0.name', 'custom_field_values.id_number'] as $path) {
            $this->assertFalse(SensitiveValueMask::isSensitive($path), $path);
        }
    }
}
