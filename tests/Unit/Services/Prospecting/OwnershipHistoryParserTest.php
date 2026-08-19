<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prospecting;

use App\Models\Prospecting\TrackedPropertyOwner;
use App\Services\Prospecting\OwnershipHistoryParser;
use App\Services\Prospecting\OwnershipOwnerRow;
use PHPUnit\Framework\TestCase;

/**
 * OwnershipHistoryParser — pure logic, no DB. .ai/specs/deeds-capture.md §7.
 *
 * These fixtures ARE the regression protection for the double-count bug and
 * the fail-closed rules, per Johan's explicit build instruction (2026-08-19):
 * the primary case is the real SEESKULP Section 4 panel data he read off
 * cmainfo himself (Wilken / Fisher / Steve du Toit Trust), plus five more
 * covering genuine two-person co-ownership, a single-owner property, an
 * entity-only owner, a list-length mismatch, and a still-masked ID.
 */
final class OwnershipHistoryParserTest extends TestCase
{
    private OwnershipHistoryParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new OwnershipHistoryParser();
    }

    /** Real panel data — .ai/specs/deeds-capture.md §7.0 / §7.12. */
    public function test_seeskulp_section_4_transfer_history(): void
    {
        $result = $this->parser->parse([
            'owner_names' => 'WILKEN JOHAN 82.7397% ; WILKEN HESTER JOHANNA CATHARINA ; WILKEN HESTER JOHANNA CATHARINA ; '
                . 'WILKEN JOHAN 15.3424% ; STEVE DU TOIT TRUST-TRUSTEES 1.9178% ; WILKEN JOHAN 1.9178% ; '
                . 'WILKEN HESTER JOHANNA CATHARINA ; FISHER RONALD GEORGE 98.0822% ; FISHER LUCILLE 0.9589% ; '
                . 'SEE-SKULP TRUST-TRUSTEES',
            'owner_ids' => '581111******* ; 620117******* ; 620117******* ; 581111******* ; IT 1203/91 ; '
                . '581111******* ; 620117******* ; 290527******* ; 340427******* ;',
            'title_deeds' => 'ST39075/2003 82.7397% ; ST39075/2003 ; ST39074/2003 ; ST39074/2003 15.3424% ; '
                . 'ST39073/2003 1.9178% ; ST6815/1993 1.9178% ; ST6815/1993 ; ST4830/1993 98.0822% ; '
                . 'ST4830/1993 0.9589% ; ST257-4',
        ], '2003-01-15', '2003-07-11');

        $this->assertSame('warning', $result->status);
        $this->assertNotNull($result->note);
        $this->assertStringContainsString('SEE-SKULP TRUST-TRUSTEES', $result->note);
        $this->assertCount(10, $result->rows);

        [$johan1, $hester1, $hester2, $johan2, $trust, $johan3, $hester3, $fisherR, $fisherL, $seeSkulp] = $result->rows;

        // Current generation (2003) — Johan + Hester jointly hold two deeds, Steve du Toit Trust holds one.
        foreach ([$johan1, $hester1, $hester2, $johan2, $trust] as $row) {
            $this->assertSame(TrackedPropertyOwner::OWNERSHIP_CURRENT, $row->ownershipStatus, $row->name);
        }
        $this->assertSame('ST39075/2003', $johan1->deedReference);
        $this->assertSame(82.7397, $johan1->sharePct);
        $this->assertSame('ST39075/2003', $hester1->deedReference);
        $this->assertSame(82.7397, $hester1->sharePct, 'joint holder — must inherit the SAME share, not null');
        $this->assertSame('ST39074/2003', $hester2->deedReference);
        $this->assertSame(15.3424, $hester2->sharePct);
        $this->assertSame('ST39074/2003', $johan2->deedReference);
        $this->assertSame(15.3424, $johan2->sharePct);
        $this->assertSame('STEVE DU TOIT TRUST-TRUSTEES', $trust->name, 'not split into first/last — that is the resolver\'s job, not the parser\'s');
        $this->assertSame('ST39073/2003', $trust->deedReference);
        $this->assertSame(1.9178, $trust->sharePct);
        $this->assertSame('IT 1203/91', $trust->idNumber, 'not masked — must survive verbatim for the resolver to route to entity_reg_no');
        $this->assertSame('trust_reg', $trust->idType);

        // Past generation (1993) — Johan + Hester's earlier joint deed, then the Fishers' sale.
        foreach ([$johan3, $hester3, $fisherR, $fisherL] as $row) {
            $this->assertSame(TrackedPropertyOwner::OWNERSHIP_PAST, $row->ownershipStatus, $row->name);
        }
        $this->assertSame('ST6815/1993', $johan3->deedReference);
        $this->assertSame(1.9178, $johan3->sharePct);
        $this->assertSame('ST6815/1993', $hester3->deedReference);
        $this->assertSame(1.9178, $hester3->sharePct, 'joint holder on the PAST deed too');
        $this->assertSame('ST4830/1993', $fisherR->deedReference);
        $this->assertSame(98.0822, $fisherR->sharePct, 'two DISTINCT shares on one deed — not a joint holding, each keeps its own');
        $this->assertSame('ST4830/1993', $fisherL->deedReference);
        $this->assertSame(0.9589, $fisherL->sharePct);

        // Masked IDs (every position here except the trust and the ID-less position 10) all null out.
        foreach ([$johan1, $hester1, $hester2, $johan2, $johan3, $hester3, $fisherR, $fisherL] as $row) {
            $this->assertNull($row->idNumber, $row->name . ' — masked ID must never be stored');
        }

        // Position 10 — SEE-SKULP TRUST-TRUSTEES: no ID given, and its deed ("ST257-4") has no
        // parseable year, so it's excluded from classification (§7.9 case 4), not dropped.
        $this->assertSame('SEE-SKULP TRUST-TRUSTEES', $seeSkulp->name);
        $this->assertNull($seeSkulp->idNumber);
        $this->assertNull($seeSkulp->deedYear);
        $this->assertNull($seeSkulp->ownershipStatus);

        // The regression case this whole spec exists for: summing per row instead of per
        // distinct-share-per-deed would give ~199%, not ~100%. Assert the CURRENT total directly.
        $currentDeedShares = [];
        foreach ($result->rows as $row) {
            if ($row->ownershipStatus === TrackedPropertyOwner::OWNERSHIP_CURRENT && $row->deedReference !== null) {
                $currentDeedShares[$row->deedReference] = $row->sharePct;
            }
        }
        $this->assertEqualsWithDelta(99.9999, array_sum($currentDeedShares), 0.0001);
    }

    /** Two DIFFERENT people, each with their own explicit share, on ONE current deed — not a joint holding. */
    public function test_two_different_people_co_ownership(): void
    {
        $result = $this->parser->parse([
            'owner_names' => 'SMITH JOHN 60% ; SMITH JANE 40%',
            'owner_ids' => '8001015009087 ; 8501015009084',
            'title_deeds' => 'T12345/2020 60% ; T12345/2020 40%',
        ], '2020-03-01', '2020-06-15');

        $this->assertSame('ok', $result->status);
        $this->assertNull($result->note);
        $this->assertCount(2, $result->rows);
        [$john, $jane] = $result->rows;

        $this->assertSame(TrackedPropertyOwner::OWNERSHIP_CURRENT, $john->ownershipStatus);
        $this->assertSame(TrackedPropertyOwner::OWNERSHIP_CURRENT, $jane->ownershipStatus);
        $this->assertSame(60.0, $john->sharePct);
        $this->assertSame(40.0, $jane->sharePct, 'own distinct share kept — must NOT be overwritten by the other owner\'s value');
        $this->assertSame('8001015009087', $john->idNumber);
        $this->assertSame('sa_id', $john->idType);
    }

    /** The simple, common case — a single owner on a single deed, no share text at all. */
    public function test_single_owner_single_deed(): void
    {
        $result = $this->parser->parse([
            'owner_names' => 'SMITH JOHN',
            'owner_ids' => '8001015009087',
            'title_deeds' => 'T99999/2015',
        ], '2015-05-01', '2015-08-20');

        $this->assertSame('ok', $result->status);
        $this->assertCount(1, $result->rows);
        $row = $result->rows[0];
        $this->assertSame('SMITH JOHN', $row->name);
        $this->assertSame(TrackedPropertyOwner::OWNERSHIP_CURRENT, $row->ownershipStatus);
        $this->assertSame('T99999/2015', $row->deedReference);
        $this->assertNull($row->sharePct, 'no share text on the page — must not be invented as 100%');
    }

    /**
     * An entity as the SOLE owner. Ground truth (Johan): "HIBISCUS COAST MUNICIPALITY" must
     * never be split into first/last name — the parser must hand the resolver the whole,
     * un-split name so entity classification (App\Support\OwnerEntityClassifier) runs on it
     * intact, not on a mangled "surname=Hibiscus" fragment.
     */
    public function test_entity_only_owner_name_is_never_split(): void
    {
        $result = $this->parser->parse([
            'owner_names' => 'HIBISCUS COAST MUNICIPALITY',
            'owner_ids' => '',
            'title_deeds' => 'T50000/2019',
        ], '2019-01-10', '2019-04-22');

        $this->assertSame('ok', $result->status);
        $this->assertCount(1, $result->rows);
        $row = $result->rows[0];
        $this->assertSame('HIBISCUS COAST MUNICIPALITY', $row->name, 'the parser must never split an entity name into surname/first-names');
        $this->assertTrue(
            \App\Support\OwnerEntityClassifier::isEntity($row->name, $row->idType, $row->idNumber),
            'sanity check — this name must classify as an entity downstream'
        );
    }

    /** §7.9 case 1 — do not guess the pairing when the three lists disagree in length. */
    public function test_list_length_mismatch_fails_closed(): void
    {
        $result = $this->parser->parse([
            'owner_names' => 'SMITH JOHN ; SMITH JANE',
            'owner_ids' => '8001015009087 ; 8501015009084',
            'title_deeds' => 'T12345/2020', // only ONE deed for two owners — genuinely mismatched
        ], '2020-03-01', '2020-06-15');

        $this->assertSame('failed', $result->status);
        $this->assertSame([], $result->rows);
        $this->assertNotNull($result->note);
        $this->assertStringContainsString('owner=2', $result->note);
        $this->assertStringContainsString('deed=1', $result->note);
    }

    /**
     * §7.9 case 5 — an ID that never unmasked (cc6's reveal fix has to handle ten positions now,
     * not one) must be absorbed, not stored as a partial value, and must NOT fail the capture.
     */
    public function test_still_masked_id_nulls_out_without_failing(): void
    {
        $result = $this->parser->parse([
            'owner_names' => 'SMITH JOHN',
            'owner_ids' => '581111*******',
            'title_deeds' => 'T12345/2010',
        ], '2010-02-01', '2010-05-01');

        $this->assertSame('ok', $result->status, 'a masked ID is absorbed, never a fail-closed reason');
        $this->assertCount(1, $result->rows);
        $this->assertNull($result->rows[0]->idNumber, 'must never store a partial/masked value');
        $this->assertSame(TrackedPropertyOwner::OWNERSHIP_CURRENT, $result->rows[0]->ownershipStatus);
    }

    /** §7.9 case 2 — deed-year generation present, but it doesn't match the panel's dates. */
    public function test_deed_year_disagrees_with_panel_dates_fails_closed(): void
    {
        $result = $this->parser->parse([
            'owner_names' => 'SMITH JOHN',
            'owner_ids' => '8001015009087',
            'title_deeds' => 'T12345/1993',
        ], '2010-02-01', '2010-05-01'); // panel says 2010, only deed on record is 1993

        $this->assertSame('failed', $result->status);
        $this->assertSame([], $result->rows);
        $this->assertStringContainsString('1993', $result->note);
        $this->assertStringContainsString('2010', $result->note);
    }

    /** §7.6 — a current-generation share total meaningfully off ~100% is a WARNING, not a failure. */
    public function test_current_share_total_off_100_percent_warns_but_still_links(): void
    {
        $result = $this->parser->parse([
            'owner_names' => 'SMITH JOHN 60%',
            'owner_ids' => '8001015009087',
            'title_deeds' => 'T12345/2020 60%',
        ], '2020-03-01', '2020-06-15');

        $this->assertSame('warning', $result->status);
        $this->assertStringContainsString('60', $result->note);
        $this->assertCount(1, $result->rows);
        $this->assertSame(
            TrackedPropertyOwner::OWNERSHIP_CURRENT,
            $result->rows[0]->ownershipStatus,
            'current owner is still captured and linkable despite the share shortfall'
        );
    }
}
