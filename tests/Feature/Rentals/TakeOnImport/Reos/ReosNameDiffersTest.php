<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport\Reos;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use App\Services\Rentals\TakeOnImport\Reos\ReosDecisionService;
use App\Services\Rentals\TakeOnImport\Reos\ReosNameCompare;
use App\Services\Rentals\TakeOnImport\Reos\ReosRunSummary;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AT-479 (Johan, 21 Oct) — a contact matched on phone / e-mail under a DIFFERENT name than reOS gives must say so: "Name differs",
 * or "matched a person (likely the company's contact person)" when reOS names a company and CoreX has a person. The matched
 * contact stays the default ("Use the contact already in CoreX"); the flag is a warning, never a blocker; there is a filter.
 * Synthetic data only ("ZZ").
 *
 * Paths: person vs a different person / company vs a person / person vs a company / same person written another way (initial,
 * case, order, punctuation, legal suffix, a joint name matching one spouse) / the flag clears when the user creates a new contact
 * or leaves the person out / the filter and the header count.
 */
final class ReosNameDiffersTest extends TestCase
{
    use RefreshDatabase;
    use ReosFixtures;

    protected function tearDown(): void
    {
        $this->tearDownReosFiles();
        parent::tearDown();
    }

    public function test_name_comparison_rules(): void
    {
        $same = [
            ['Andre Smith', 'Andre Smith'], ['A Smith', 'Andre Smith'], ['andre  SMITH', 'Smith, Andre'], ["Andre J. Smith", 'Andre Smith'],
            ['William & Dorothy Geyser', 'William Geyser'], ['Wildevy Properties Pty Ltd', 'Wildevy Properties (PTY) LTD'], ['ZZ Holdings CC', 'ZZ Holdings CC'],
        ];
        foreach ($same as [$a, $b]) {
            self::assertTrue(ReosNameCompare::sameName($a, $b), "{$a} = {$b}");
        }

        $different = [['Aiesha Steady', 'Elana Reid'], ['Andre Esterhuizen', 'Johan Esterhuizen'], ['Exilite 104 CC', 'Ray Herbert'], ['B Smith', 'Andre Smith']];
        foreach ($different as [$a, $b]) {
            self::assertFalse(ReosNameCompare::sameName($a, $b), "{$a} != {$b}");
        }

        self::assertSame(ReosNameCompare::DIFFERS, ReosNameCompare::flag('Aiesha Steady', 'Elana Reid', false));
        self::assertSame(ReosNameCompare::PERSON_FOR_COMPANY, ReosNameCompare::flag('Wildevy Properties Pty Ltd', 'Arne Nel', false));
        self::assertSame(ReosNameCompare::PERSON_FOR_COMPANY, ReosNameCompare::flag('Exilite 104 CC', 'Ray Herbert', false), 'company vs person even when the names are plainly unrelated');
        self::assertSame(ReosNameCompare::DIFFERS, ReosNameCompare::flag('Ray Herbert', 'Exilite 104 CC', true), 'a person matched to a company');
        self::assertNull(ReosNameCompare::flag('Wildevy Properties Pty Ltd', 'Wildevy Properties (Pty) Ltd', true));
        self::assertNull(ReosNameCompare::flag('A Smith', 'Andre Smith', false));
    }

    public function test_a_contact_matched_on_phone_under_a_different_name_is_flagged_but_stays_the_default_and_never_blocks(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $this->contact($agency, $branch, 'Elana', 'Reid', '0635550102');        // the reOS tenant's number
        $this->contact($agency, $branch, 'Arne', 'Nel', '0815550101');          // the reOS company-owner's number
        $run = $this->makeRun($admin, [$this->reosRow(['Owner' => 'ZZ TEST Wildevy Properties Pty Ltd', 'Tenant' => 'ZZ TEST Aiesha Steady'])]);
        $row = $run->rows()->first();

        $tenant = $row->tenant_match_json[1];
        $owner = $row->landlord_match_json[1];
        self::assertSame('match', $tenant['action']);
        self::assertSame('differs', $tenant['name_flag']);
        self::assertSame('Elana Reid', $tenant['matched_name']);
        self::assertSame('match', $owner['action']);
        self::assertSame('person_for_company', $owner['name_flag']);
        self::assertSame('Arne Nel', $owner['matched_name']);

        // Default stays "use the contact already in CoreX"; a warning, not a blocker.
        self::assertSame('link', $row->decisions_json['tenant']['action']);
        self::assertSame($tenant['existing_contact_id'], $row->decisions_json['tenant']['contact_id']);
        self::assertTrue($row->decisions_json['name_differs']);
        self::assertNotContains('name_differs', collect($row->blockers_json)->pluck('code')->all(), 'a name warning is not a blocker');
        $row = app(ReosDecisionService::class)->apply($row, $run, ['selected' => true, 'suburb_id' => $this->p24Suburb()->id]);
        self::assertFalse($row->is_blocked, json_encode($row->blockers_json));

        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringContainsString('Name differs', $html);
        self::assertStringContainsString('Elana Reid', $html);
        self::assertStringContainsString('Matched a person', $html);
        self::assertStringContainsString("likely the company's contact person", html_entity_decode($html));
        self::assertStringContainsString('data-name-flag="differs"', $html);
        self::assertStringContainsString('data-name-flag="person_for_company"', $html);
        self::assertStringContainsString('Use the contact already in CoreX', $html);
        self::assertSame(1, ReosRunSummary::for($run)['name_differs']);
    }

    public function test_the_same_person_written_another_way_is_not_flagged(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $this->contact($agency, $branch, 'Andre', 'Smith', '0635550102');
        $this->contact($agency, $branch, '', '', '0815550101', ['contact_kind' => 'entity', 'entity_name' => 'Wildevy Properties (PTY) LTD', 'first_name' => 'Wildevy Properties (PTY) LTD']);
        $run = $this->makeRun($admin, [$this->reosRow(['Owner' => 'ZZ Other Properties Pty Ltd', 'Tenant' => 'A. SMITH'])]);
        $row = $run->rows()->first();

        // Tenant "A. SMITH" = Andre Smith (initial, case, punctuation): not flagged. The owner company is a different company.
        self::assertArrayNotHasKey('name_flag', $row->tenant_match_json[1]);
        self::assertSame('differs', $row->landlord_match_json[1]['name_flag'] ?? null);

        $run2 = $this->makeRun($admin, [$this->reosRow(['uuid' => self::UUID_B, 'Owner' => 'Wildevy Properties Pty Ltd', 'Tenant' => 'A. SMITH'])]);
        $row2 = $run2->rows()->first();
        self::assertArrayNotHasKey('name_flag', $row2->landlord_match_json[1], 'same company, different suffix spelling');
        self::assertFalse($row2->decisions_json['name_differs']);
        self::assertSame(0, ReosRunSummary::for($run2)['name_differs']);
        $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run2))->assertOk()->assertDontSee('Name differs —')->assertDontSee('Matched a person');
    }

    public function test_the_flag_clears_when_the_user_creates_a_new_contact_or_leaves_the_person_out_or_links_another(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $other = $this->contact($agency, $branch, 'Pat', 'Other', '0815550999');
        $this->contact($agency, $branch, 'Elana', 'Reid', '0635550102');
        $run = $this->makeRun($admin, [$this->reosRow(['Owner' => 'ZZ TEST Owner One', 'Owner Email' => '', 'Owner Phone' => '', 'Tenant' => 'ZZ TEST Aiesha Steady'])]);
        $svc = app(ReosDecisionService::class);
        $row = $run->rows()->first();
        self::assertTrue($row->decisions_json['name_differs']);

        $row = $svc->apply($row, $run, ['tenant' => ['action' => 'create']]);
        self::assertFalse($row->decisions_json['name_differs'], 'creating a new contact leaves nothing to warn about');
        self::assertSame(0, ReosRunSummary::for($run)['name_differs']);

        $row = $svc->apply($row, $run, ['tenant' => ['action' => 'link', 'contact_id' => $row->tenant_match_json[1]['existing_contact_id']]]);
        self::assertTrue($row->decisions_json['name_differs'], 'back to the matched contact: the warning is back');

        $row = $svc->apply($row, $run, ['tenant' => ['action' => 'skip']]);
        self::assertFalse($row->decisions_json['name_differs']);

        $row = $svc->apply($row, $run, ['tenant' => ['action' => 'link', 'contact_id' => $other->id]]);
        self::assertFalse($row->decisions_json['name_differs'], 'a contact the user picked by hand is their own decision');
    }

    public function test_the_name_differs_filter_lists_only_flagged_leases(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $this->contact($agency, $branch, 'Elana', 'Reid', '0635550102');
        $run = $this->makeRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A, 'Property Address' => '3 ZZ Flagged Road', 'Tenant' => 'ZZ TEST Aiesha Steady']),
            $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '9 ZZ Clean Drive', 'Tenant' => 'Elana Reid', 'Owner' => 'ZZ TEST Owner Two', 'Owner Email' => 'zz.o2@example.test', 'Owner Phone' => '+27815550202']),
        ]);
        $url = route('corex.rentals.take-on-import.preview', $run);

        $this->actingAs($admin)->get($url)->assertOk()->assertSee('3 ZZ Flagged Road')->assertSee('9 ZZ Clean Drive')->assertSee('Contact names');
        $this->actingAs($admin)->get($url . '?names=differs')->assertOk()->assertSee('3 ZZ Flagged Road')->assertDontSee('9 ZZ Clean Drive');
        self::assertSame(1, ReosRunSummary::for($run)['name_differs']);
    }

    // ── Johan's real-file check: spelling vs a real difference; companies are not people; the layout fits ──

    public function test_spelling_differences_are_told_apart_from_real_ones_using_the_real_examples(): void
    {
        $spelling = [
            ['Sipho Mkhozi', 'Sipho Mkozi'],                   // Mkhozi / Mkozi
            ['Shaun Craig Mc Neil', 'Shaun McNeil'],           // "Mc Neil" = "McNeil", a middle name on one side
            ['Desire Jones', 'Desiree Jones'],                 // Desire / Desiree
            ['Exilite 104 CC', 'Exilite 140 CC'],              // a typo inside a company name
        ];
        foreach ($spelling as [$reos, $crm]) {
            $company = \App\Services\Rentals\TakeOnImport\Reos\ReosContactNames::isCompany($reos);
            self::assertSame(ReosNameCompare::SPELLING, ReosNameCompare::flag($reos, $crm, $company), "{$reos} ~ {$crm}");
        }

        $real = [['Aiesha Steady', 'Elana Reid'], ['Andre Esterhuizen', 'Johan Esterhuizen'], ['B Smith', 'Andre Smith'], ['Sipho Dlamini', 'Sipho Nkosi']];
        foreach ($real as [$reos, $crm]) {
            self::assertSame(ReosNameCompare::DIFFERS, ReosNameCompare::flag($reos, $crm, false), "{$reos} != {$crm}");
        }

        // The same name, or only a middle name / legal suffix apart, is not flagged at all.
        foreach ([['John Smith', 'John Peter Smith'], ['A Smith', 'Andre Smith'], ['BEBO Investments (PTY) LTD', 'BEBO Investments (PTY) LTY']] as [$a, $b]) {
            self::assertNull(ReosNameCompare::flag($a, $b, \App\Services\Rentals\TakeOnImport\Reos\ReosContactNames::isCompany($a)), "{$a} = {$b}");
        }

        // Short names are not "a typo apart": Ben / Ken are different people.
        self::assertSame(ReosNameCompare::DIFFERS, ReosNameCompare::flag('Ben Smith', 'Ken Smith', false));
        self::assertContains(ReosNameCompare::DIFFERS, ReosNameCompare::AMBER);
        self::assertNotContains(ReosNameCompare::SPELLING, ReosNameCompare::AMBER);
    }

    public function test_first_name_and_surname_the_wrong_way_round_is_a_spelling_difference_not_a_name_difference(): void
    {
        // Johan's real example (21 Oct): CoreX "Nkome Langelihle", reOS "Langelihle Nkomo" — swapped, plus a one-letter typo.
        self::assertSame(ReosNameCompare::SPELLING, ReosNameCompare::flag('Langelihle Nkomo', 'Nkome Langelihle', false));
        self::assertSame(ReosNameCompare::SPELLING, ReosNameCompare::flag('Nkome Langelihle', 'Langelihle Nkomo', false), 'either way round');
        // Swapped and spelled the same: the name is the same set of words, so it is not flagged at all.
        self::assertNull(ReosNameCompare::flag('Langelihle Nkomo', 'Nkomo Langelihle', false));
        // A middle name on one side does not stop the swap being recognised.
        self::assertSame(ReosNameCompare::SPELLING, ReosNameCompare::flag('Langelihle Nkomo', 'Nkome Thabo Langelihle', false));

        // Still real differences: a different surname, a different first name, a single word against two, short names.
        self::assertSame(ReosNameCompare::DIFFERS, ReosNameCompare::flag('Langelihle Nkomo', 'Dlamini Langelihle', false));
        self::assertSame(ReosNameCompare::DIFFERS, ReosNameCompare::flag('Langelihle Nkomo', 'Thabo Nkomo', false));
        self::assertSame(ReosNameCompare::DIFFERS, ReosNameCompare::flag('Mkhozi', 'Thabo Mkozi', false), 'one word has no order to swap');
        self::assertSame(ReosNameCompare::DIFFERS, ReosNameCompare::flag('Ben Smith', 'Smith Ken', false));
    }

    public function test_a_company_matched_to_a_company_is_a_company_match_even_when_corex_holds_it_as_a_person_record(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        // As on QA1: a "natural person" record whose first name is the company, with a typo in the legal suffix.
        $bebo = $this->contact($agency, $branch, 'BEBO Investments (PTY) LTY', '', '0815552014');
        // A tenant company stored the same way.
        $tenantCo = $this->contact($agency, $branch, 'ZZ Tenant Holdings Pty Ltd', '', '0635552015');
        $run = $this->makeRun($admin, [$this->reosRow([
            'Owner' => 'BEBO Investments (PTY) LTD', 'Owner Phone' => '+27815552014', 'Owner Email' => '',
            'Tenant' => 'ZZ Tenant Holdings Pty Ltd', 'Tenant Phone' => '+27635552015', 'Tenant Email' => '',
        ])]);
        $row = $run->rows()->first();

        self::assertSame($bebo->id, $row->landlord_match_json[1]['existing_contact_id']);
        self::assertArrayNotHasKey('name_flag', $row->landlord_match_json[1], 'company to company: no "matched a person"');
        self::assertArrayNotHasKey('name_flag', $row->tenant_match_json[1], 'same check for tenants');
        self::assertSame($tenantCo->id, $row->tenant_match_json[1]['existing_contact_id']);
        self::assertFalse($row->decisions_json['name_differs']);

        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringNotContainsString('Matched a person', $html);
        self::assertStringNotContainsString('as its contact person', html_entity_decode($html), 'an entity is never offered as somebody\'s contact person');
        self::assertStringNotContainsString('data-test="convert-to-company"', $html);

        // The one-click is refused for it, and a company-like record is refused as a "person" of another company.
        try {
            app(ReosDecisionService::class)->apply($row, $run, ['person' => ['party' => 'owner', 'op' => 'convert']]);
            self::fail('convert must be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('did not match a person', json_encode($e->errors()));
        }
        $other = $this->makeRun($admin, [$this->reosRow(['uuid' => self::UUID_B, 'Owner' => 'ZZ TEST Other Holdings Pty Ltd', 'Owner Phone' => '', 'Owner Email' => ''])]);
        $this->expectException(ValidationException::class);
        app(ReosDecisionService::class)->apply($other->rows()->first(), $other, ['person' => ['party' => 'owner', 'op' => 'add', 'contact_id' => $bebo->id]]);
    }

    public function test_spelling_is_grey_not_counted_and_has_its_own_filter_while_real_differences_stay_amber_and_counted(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $this->contact($agency, $branch, 'Sipho', 'Mkozi', '0635550201');          // spelling apart from the reOS "Mkhozi"
        $this->contact($agency, $branch, 'Elana', 'Reid', '0635550202');           // a real difference from "Aiesha Steady"
        $run = $this->makeRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A, 'Property Address' => '3 ZZ Spelling Road', 'Tenant' => 'Sipho Mkhozi', 'Tenant Phone' => '+27635550201', 'Tenant Email' => '', 'Owner' => 'ZZ O One', 'Owner Phone' => '', 'Owner Email' => '']),
            $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '9 ZZ Real Drive', 'Tenant' => 'Aiesha Steady', 'Tenant Phone' => '+27635550202', 'Tenant Email' => '', 'Owner' => 'ZZ O Two', 'Owner Phone' => '', 'Owner Email' => '']),
        ]);
        $rows = $run->rows()->orderBy('row_number')->get();

        self::assertSame('spelling', $rows[0]->tenant_match_json[1]['name_flag']);
        self::assertTrue($rows[0]->decisions_json['spelling_differs']);
        self::assertFalse($rows[0]->decisions_json['name_differs'], 'a spelling difference is not in the amber total');
        self::assertSame('differs', $rows[1]->tenant_match_json[1]['name_flag']);
        self::assertTrue($rows[1]->decisions_json['name_differs']);
        self::assertFalse($rows[1]->decisions_json['spelling_differs']);

        $sum = ReosRunSummary::for($run);
        self::assertSame([1, 1], [$sum['name_differs'], $sum['spelling_differs']]);

        $url = route('corex.rentals.take-on-import.preview', $run);
        $page = $this->actingAs($admin)->get($url)->assertOk();
        $page->assertSee('Spelling differs —', false)->assertSee('Name differs</strong> —', false)->assertSee('data-test="spelling-chip"', false);
        $this->actingAs($admin)->get($url . '?names=spelling')->assertOk()->assertSee('3 ZZ Spelling Road')->assertDontSee('9 ZZ Real Drive');
        $this->actingAs($admin)->get($url . '?names=differs')->assertOk()->assertSee('9 ZZ Real Drive')->assertDontSee('3 ZZ Spelling Road');
        $this->actingAs($admin)->get($url)->assertSee('Spelling differs</option>', false);

        // Settled by creating a new contact: the spelling note goes too.
        $row = app(ReosDecisionService::class)->apply($rows[0], $run, ['tenant' => ['action' => 'create']]);
        self::assertFalse($row->decisions_json['spelling_differs']);
    }

    public function test_the_review_screen_fits_the_page_and_the_sticky_header_is_solid_and_above_the_rows(): void
    {
        [, , $admin] = $this->reosAgency();
        $run = $this->makeRun($admin, [$this->reosRow()]);

        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();

        // 1. Sticky header: solid background, above every row control (their result lists are z-20).
        self::assertMatchesRegularExpression('/id="reos-bar"[^>]*position: sticky[^>]*z-index: 50[^>]*background: var\(--surface/', $html);
        // 1b. Flush with the top of the scroll area (<main id="appScroll"> has padding, so top:0 would leave a strip of rows above the bar):
        //     a negative top equal to that padding, and no top:0 anywhere on the bar.
        self::assertMatchesRegularExpression('/#reos-bar\s*\{\s*top:\s*-1rem;/', $html);
        self::assertMatchesRegularExpression('/min-width:\s*1024px\)\s*\{\s*#reos-bar\s*\{\s*top:\s*-1\.5rem;/', $html);
        self::assertDoesNotMatchRegularExpression('/id="reos-bar"[^>]*top:\s*0/', $html);
        // 4. Grid fits without a horizontal page scroll: fixed layout, percentage columns that add up to 100, no fixed minimum widths,
        //    controls capped to their column, and the to-fix notes inside the agent cell (six columns, not seven).
        self::assertStringContainsString('table-layout: fixed', $html);
        preg_match_all('/<col style="width:(\d+)%">/', $html, $cols);
        self::assertCount(6, $cols[1]);
        self::assertSame(100, array_sum(array_map('intval', $cols[1])));
        $table = substr($html, strpos($html, 'id="reos-table"'), strpos($html, '</table>') - strpos($html, 'id="reos-table"'));
        self::assertStringNotContainsString('min-w-[', $table, 'no fixed minimum widths inside the review table');
        self::assertSame(6, preg_match_all('/<th[ >]/', substr($html, strpos($html, 'id="reos-table"'), 1800)), 'six header cells');
        self::assertDoesNotMatchRegularExpression('/max-width:\d+(\.\d+)?rem/', substr($html, strpos($html, '<tbody>'), strpos($html, '</tbody>') - strpos($html, '<tbody>')), 'no control wider than its column');
        self::assertMatchesRegularExpression('/Agent · to fix \/ to know/', $html);
    }

    public function test_a_batch_built_under_the_old_rules_is_rechecked_once_when_it_is_opened(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $this->contact($agency, $branch, 'BEBO Investments (PTY) LTY', '', '0815552014');
        $this->contact($agency, $branch, 'Sipho', 'Mkozi', '0635550201');
        $run = $this->makeRun($admin, [$this->reosRow(['Owner' => 'BEBO Investments (PTY) LTD', 'Owner Phone' => '+27815552014', 'Owner Email' => '', 'Tenant' => 'Sipho Mkhozi', 'Tenant Phone' => '+27635550201', 'Tenant Email' => ''])]);
        $row = $run->rows()->first();

        // Put the batch back as the OLD rules left it: the company read as "matched a person", the typo as an amber difference.
        $lm = $row->landlord_match_json; $lm[1]['name_flag'] = 'person_for_company'; $lm[1]['matched_name'] = 'BEBO Investments (PTY) LTY';
        $tm = $row->tenant_match_json; $tm[1]['name_flag'] = 'differs'; $tm[1]['matched_name'] = 'Sipho Mkozi';
        $d = $row->decisions_json; $d['name_differs'] = true; unset($d['spelling_differs']);
        $row->forceFill(['landlord_match_json' => $lm, 'tenant_match_json' => $tm, 'decisions_json' => $d])->save();
        $counts = $run->counts_json; unset($counts['name_flags_v']); $run->forceFill(['counts_json' => $counts])->save();

        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();

        $row = $run->rows()->first();
        self::assertArrayNotHasKey('name_flag', $row->landlord_match_json[1], 'the company is no longer "a person"');
        self::assertSame('spelling', $row->tenant_match_json[1]['name_flag']);
        self::assertSame([false, true], [$row->decisions_json['name_differs'], $row->decisions_json['spelling_differs']]);
        self::assertSame(\App\Services\Rentals\TakeOnImport\Reos\ReosRowResolver::NAME_FLAGS_VERSION, $run->fresh()->counts_json['name_flags_v']);
        self::assertStringNotContainsString('Matched a person', $html);
        self::assertStringContainsString('Spelling differs —', $html);
        self::assertSame(0, ReosRunSummary::for($run)['name_differs']);
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    private function makeRun(User $user, array $rows): RentalTakeOnImportRun
    {
        $this->actingAs($user);

        return app(ReosTenancyRunService::class)->createRun($user, (int) $user->agency_id, $user->branch_id, $this->upload($this->scheduleFile($rows)));
    }

    private function contact(Agency $agency, Branch $branch, string $first, string $last, string $phone, array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
        ], $extra));
    }
}
