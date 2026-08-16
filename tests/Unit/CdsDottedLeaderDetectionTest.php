<?php

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use PHPUnit\Framework\TestCase;

/**
 * AT-304 OTP — the OTP "Offer to Purchase" uses DOTTED-LEADER fill blanks (not typed markers),
 * amount-pairs "R… (…words…)", an agency split, and an N-party signature page with witnesses.
 * These cover the DB-free detection halves.
 */
class CdsDottedLeaderDetectionTest extends TestCase
{
    private CdsParserService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CdsParserService();
    }

    private function call(string $method, array $sections): array
    {
        $m = new \ReflectionMethod($this->svc, $method);
        $m->setAccessible(true);
        return $m->invoke($this->svc, $sections);
    }

    private function para(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => $text]]];
    }

    private function fieldCount(array $sections): int
    {
        $n = 0;
        foreach ($sections as $s) {
            foreach ($s['content'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'field_placeholder') {
                    $n++;
                }
            }
        }
        return $n;
    }

    // ── OTP-1: dotted-leader tokenizer ───────────────────────────────────────

    public function test_dotted_leaders_become_fields_multiple_per_line(): void
    {
        $out = $this->call('detectDottedLeaderFields', [
            $this->para('Freehold Stand No: ............ in the Township of ............, District ............'),
        ]);
        $this->assertSame(3, $this->fieldCount($out), 'three dotted blanks on one line → three fields');
    }

    public function test_ellipsis_is_not_tokenised(): void
    {
        $out = $this->call('detectDottedLeaderFields', [
            $this->para('The parties agree… and the rest follows... as discussed.'),
        ]);
        $this->assertSame(0, $this->fieldCount($out), 'ordinary ellipsis (…, ...) must NOT tokenise');
    }

    public function test_signature_area_lines_are_left_for_the_sig_detectors(): void
    {
        $out = $this->call('detectDottedLeaderFields', [
            $this->para('As Witnesses: 1. ............ 2. ............'),
        ]);
        $this->assertSame(0, $this->fieldCount($out), 'witness/signature lines are not tokenised as inputs here');
    }

    // ── OTP-4: amount-pair linkage + agency split ────────────────────────────

    public function test_amount_pair_is_linked(): void
    {
        $sections = [[
            'type' => 'clause',
            'content' => [
                ['type' => 'text', 'value' => 'The purchase price is R'],
                ['type' => 'field_placeholder'],
                ['type' => 'text', 'value' => ' ('],
                ['type' => 'field_placeholder'],
                ['type' => 'text', 'value' => ')'],
            ],
        ]];
        $out = $this->call('refineAmountPairsAndAgencySplit', $sections);
        $fields = array_values(array_filter($out[0]['content'], fn ($c) => ($c['type'] ?? '') === 'field_placeholder'));
        $this->assertSame('property.price', $fields[0]['field_name']);
        $this->assertSame('property.price_in_words', $fields[1]['field_name']);
        $this->assertSame('property.price', $fields[1]['linked_to'], 'the words half links to the amount');
    }

    public function test_agency_split_fields_are_named(): void
    {
        $sections = [[
            'type' => 'clause',
            'content' => [
                ['type' => 'text', 'value' => '5.6 Listing agency '],
                ['type' => 'field_placeholder'],
                ['type' => 'text', 'value' => ' share '],
                ['type' => 'field_placeholder'],
                ['type' => 'text', 'value' => '% FFC '],
                ['type' => 'field_placeholder'],
                ['type' => 'text', 'value' => '; Selling agency '],
                ['type' => 'field_placeholder'],
                ['type' => 'text', 'value' => ' share '],
                ['type' => 'field_placeholder'],
                ['type' => 'text', 'value' => '% FFC '],
                ['type' => 'field_placeholder'],
            ],
        ]];
        $out = $this->call('refineAmountPairsAndAgencySplit', $sections);
        $names = [];
        foreach ($out[0]['content'] as $c) {
            if (($c['type'] ?? '') === 'field_placeholder') {
                $names[] = $c['field_name'];
            }
        }
        $this->assertSame([
            'agency.listing_name', 'agency.listing_share', 'agency.listing_ffc',
            'agency.selling_name', 'agency.selling_share', 'agency.selling_ffc',
        ], $names);
    }

    // ── OTP-3: signature roster captures witnesses despite dotted leaders ─────

    public function test_extract_party_roles_captures_witness_and_practitioner(): void
    {
        $roles = $this->call('extractPartyRoles', [
            $this->para('Purchaser: ............'),
            $this->para('As Witnesses: 1. ............ 2. ............'),
            $this->para('Seller: ............'),
            $this->para('Property Practitioner: ............'),
        ]);
        $found = array_column($roles, 'role');
        $this->assertContains('witness', $found, 'witness captured even though the line carries dotted leaders');
        $this->assertContains('buyer', $found);
        $this->assertContains('seller', $found);
        $this->assertContains('agent', $found, 'property practitioner → agent');
    }
}
