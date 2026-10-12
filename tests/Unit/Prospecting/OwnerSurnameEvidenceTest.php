<?php

declare(strict_types=1);

namespace Tests\Unit\Prospecting;

use App\Services\Prospecting\OwnerSurnameEvidence;
use PHPUnit\Framework\TestCase;

/**
 * CMA pre-check — the owner SURNAME reading (spec deeds-capture.md §9.12). Pure: no database.
 * CMA's owner cell is surname-first; a compound surname has its prefix words trailing.
 */
final class OwnerSurnameEvidenceTest extends TestCase
{
    public function test_the_surname_is_the_first_word_of_a_surname_first_name(): void
    {
        $this->assertSame('PRETORIUS', OwnerSurnameEvidence::cmaSurname('PRETORIUS LEON'));
        $this->assertSame('ZIETSMAN', OwnerSurnameEvidence::cmaSurname('ZIETSMAN PHILIPPUS JACOBUS'));
        $this->assertSame('PRETORIUS', OwnerSurnameEvidence::cmaSurname('PRETORIUS'));
    }

    public function test_a_compound_surname_with_trailing_prefix_words_is_put_back_together(): void
    {
        $this->assertSame('VAN DER MERWE', OwnerSurnameEvidence::cmaSurname('MERWE FRANCOIS PHILLIPUS VAN DER'));
        $this->assertSame('DU TOIT', OwnerSurnameEvidence::cmaSurname('TOIT PIETER DU'));
        $this->assertSame('VAN DER MERWE', OwnerSurnameEvidence::cmaSurname('VAN DER MERWE JOHAN'));
    }

    public function test_the_same_surname_however_it_is_written(): void
    {
        $key = OwnerSurnameEvidence::surnameKey('Van der Merwe');
        foreach (['VAN DER MERWE', 'van der merwe', 'Vandermerwe', 'Van-der-Merwe', "Van der Merwe "] as $written) {
            $this->assertSame($key, OwnerSurnameEvidence::surnameKey($written), $written);
        }
        $this->assertTrue(OwnerSurnameEvidence::sameSurname(OwnerSurnameEvidence::surnameKey('Pretorius'), OwnerSurnameEvidence::surnameKey('PRETORIUS')));
        $this->assertTrue(OwnerSurnameEvidence::sameSurname('vandermerwe', 'merwe'), 'a compound prefix on one side only is tolerated');
        $this->assertTrue(OwnerSurnameEvidence::sameSurname(OwnerSurnameEvidence::surnameKey('Smith-Jones'), OwnerSurnameEvidence::surnameKey('Smith Jones')));
        $this->assertTrue(OwnerSurnameEvidence::sameSurname(OwnerSurnameEvidence::surnameKey('Müller'), OwnerSurnameEvidence::surnameKey('MULLER')));
    }

    public function test_different_surnames_never_match_and_a_short_bare_key_is_not_enough(): void
    {
        $this->assertFalse(OwnerSurnameEvidence::sameSurname('pretorius', 'pretoria'));
        $this->assertFalse(OwnerSurnameEvidence::sameSurname('pretorius', ''));
        $this->assertFalse(OwnerSurnameEvidence::sameSurname('vanee', 'ee'));
    }

    public function test_cma_owners_become_one_comparable_entry_each_and_first_names_play_no_part(): void
    {
        $cma = OwnerSurnameEvidence::fromCmaOwners([
            ['name' => 'PRETORIUS LEON'],
            ['name' => 'SMIT ANNA MARIA'],
            ['name' => ''],
        ]);

        $this->assertCount(2, $cma);
        $this->assertSame('pretorius', $cma[0]['key']);
        $this->assertSame('smit', $cma[1]['key']);
        $this->assertFalse($cma[0]['entity']);
    }

    public function test_a_company_or_trust_owner_is_compared_by_its_name_without_the_legal_markers(): void
    {
        $cma = OwnerSurnameEvidence::fromCmaOwners([['name' => 'THE DU BANTRY HOLDINGS (PTY) LTD', 'id_type' => 'company_reg']]);

        $this->assertTrue($cma[0]['entity']);
        $this->assertSame('du bantry holdings', $cma[0]['entityKey']);
        $this->assertSame('du bantry holdings', OwnerSurnameEvidence::entityKey('Du Bantry Holdings Pty Ltd'));

        $trust = OwnerSurnameEvidence::fromCmaOwners([['name' => 'PRETORIUS FAMILY TRUST']]);
        $this->assertTrue($trust[0]['entity'], 'a trust name is an entity, not a surname "Pretorius"');
    }
}
