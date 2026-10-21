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
