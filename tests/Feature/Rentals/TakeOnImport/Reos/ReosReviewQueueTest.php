<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport\Reos;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use App\Services\Rentals\TakeOnImport\Reos\ReosAgentMatcher;
use App\Services\Rentals\TakeOnImport\Reos\ReosQuestions;
use App\Services\Rentals\TakeOnImport\Reos\ReosRunSummary;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyConfirmService;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-481 (Johan, 22 Oct) — the rebuilt reOS review screen: every lease is imported / failed / needs you / ready; the open questions are
 * asked in plain words and answered with buttons (saved at once); the agent and suburb "for all" reach every lease that has none of its
 * own; Import takes exactly the ready leases. Synthetic data only ("ZZ", @example.test, made-up uuids).
 */
final class ReosReviewQueueTest extends TestCase
{
    use RefreshDatabase;
    use ReosFixtures;

    private const UUID_E = '11111111-2222-3333-4444-555555555556';

    protected function tearDown(): void
    {
        $this->tearDownReosFiles();
        parent::tearDown();
    }

    // ── states, header, structure ───────────────────────────────────────────────────────────

    public function test_every_lease_is_in_exactly_one_state_and_the_header_line_counts_them(): void
    {
        [$run, $admin] = $this->mixedRun();
        $rows = $run->rows()->orderBy('row_number')->get()->keyBy('external_id');

        self::assertSame(ReosQuestions::READY, ReosQuestions::state($rows[self::UUID_A]));
        self::assertSame(ReosQuestions::NEEDS, ReosQuestions::state($rows[self::UUID_B]));
        self::assertSame(['suburb'], ReosQuestions::needs($rows[self::UUID_B]), 'a new property with no suburb asks for one');
        self::assertSame(ReosQuestions::NEEDS, ReosQuestions::state($rows[self::UUID_C]));
        self::assertSame(['name:tenant'], ReosQuestions::needs($rows[self::UUID_C]));
        self::assertTrue($rows[self::UUID_A]->selected && ! $rows[self::UUID_B]->selected && ! $rows[self::UUID_C]->selected, 'ready = what Import takes');

        $s = ReosRunSummary::for($run);
        self::assertSame([3, 1, 2, 0, 0], [$s['total'], $s['ready'], $s['needs'], $s['failed'], $s['imported']]);
        self::assertSame('3 leases · 1 ready · 2 need you · 0 imported', $s['line']);
        self::assertSame('Import the 1 ready lease', $s['import_label']);
        self::assertTrue($s['can_import']);

        // A lease the file itself rejects is FAILED, not a question.
        $run2 = $this->makeRun($admin, [$this->reosRow(['uuid' => self::UUID_D, 'Lease Status' => 'Nonsense'])]);
        self::assertSame(ReosQuestions::FAILED, ReosQuestions::state($run2->rows()->first()));
        self::assertSame('1 lease · 0 ready · 0 need you · 1 failed · 0 imported', ReosRunSummary::for($run2)['line']);
    }

    public function test_the_page_is_a_header_a_defaults_bar_a_queue_and_one_line_per_ready_lease_with_no_table_or_tick_boxes(): void
    {
        [$run, $admin] = $this->mixedRun();
        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();

        self::assertStringContainsString('3 leases · 1 ready · 2 need you · 0 imported', $html);
        self::assertStringContainsString('Import the 1 ready lease', $html);
        self::assertStringContainsString('Back to batches', $html);
        self::assertStringContainsString('Agent for all', $html);
        self::assertStringContainsString('Suburb for all new properties', $html);
        self::assertStringContainsString('data-test="reos-queue"', $html);
        self::assertStringContainsString('Lease <span data-queue-pos>', $html);
        // The ready lease is one line saying what will happen; the unready ones are not lines.
        self::assertSame(1, substr_count($html, 'data-test="reos-line"'));
        self::assertStringContainsString('Uses existing property · creates 2 contacts', $html);
        // The old screen's furniture is gone.
        foreach (['<table', 'type="checkbox"', 'Tick all', 'Untick all', 'Apply suburb', 'Apply agent', 'Sort by', 'Nothing below has been created yet'] as $gone) {
            self::assertStringNotContainsString($gone, $html, $gone);
        }
        // The bar still sticks flush to the top of the scroll area.
        self::assertMatchesRegularExpression('/#reos-bar\s*\{\s*top:\s*-1rem;/', $html);
    }

    public function test_the_show_switch_and_the_search_box_are_the_only_filters(): void
    {
        [$run, $admin] = $this->mixedRun();
        $url = route('corex.rentals.take-on-import.preview', $run);

        $ready = $this->actingAs($admin)->get($url . '?show=ready')->assertOk()->getContent();
        self::assertStringContainsString('13 ZZ Stafford Ave', $ready);
        self::assertStringNotContainsString('99 ZZ Nowhere Road', $ready);
        self::assertStringNotContainsString('data-test="reos-queue"', $ready);

        $needs = $this->actingAs($admin)->get($url . '?show=needs')->assertOk()->getContent();
        self::assertStringContainsString('data-test="reos-queue"', $needs);
        self::assertStringNotContainsString('data-test="reos-line"', $needs, 'leases that need you are asked in the queue, not listed');

        $this->actingAs($admin)->get($url . '?show=failed')->assertOk()->assertSee('No leases match');
        $this->actingAs($admin)->get($url . '?show=imported')->assertOk()->assertSee('No leases match');
        $this->actingAs($admin)->get($url . '?q=Nowhere')->assertOk()->assertSee('99 ZZ Nowhere Road')->assertDontSee('13 ZZ Stafford Ave');
        $this->actingAs($admin)->get($url . '?q=nothing-matches-this')->assertOk()->assertSee('No leases match');
        foreach (['property=matched', 'readiness=blocked', 'lease_status=Draft', 'names=differs', 'sort=address'] as $old) {
            $this->actingAs($admin)->get($url . '?' . $old)->assertOk()->assertSee('13 ZZ Stafford Ave', false);   // the six old dropdowns do nothing now
        }
    }

    // ── the questions ───────────────────────────────────────────────────────────────────────

    public function test_name_differs_is_asked_as_a_question_with_the_phone_and_three_answers(): void
    {
        [$run, $admin] = $this->mixedRun();
        $row = $this->rowOf($run, self::UUID_C);

        $card = $this->actingAs($admin)->getJson(route('api.v1.rentals.take-on-import.card', $row))->assertOk()->json('card_html');
        self::assertStringContainsString('Tenant: is CoreX’s “Elana Reid” (0635550299) the same person as reOS’s “ZZ TEST Tenant Two”?', html_entity_decode($card));
        foreach (['Same person', 'New contact', 'Pick another contact…'] as $button) {
            self::assertStringContainsString($button, $card);
        }
        self::assertSame(1, substr_count($card, 'data-q="'), 'only the open question is shown');

        // Same person → ready, counted, and the lease is a line now.
        $res = $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['confirm' => 'tenant'])->assertOk();
        $res->assertJsonPath('state', 'ready')->assertJsonPath('summary.ready', 2)->assertJsonPath('summary.needs', 1);
        self::assertNull($res->json('card_html'));
        self::assertStringContainsString('data-test="reos-line"', $res->json('line_html'));
        self::assertTrue($row->fresh()->decisions_json['tenant']['confirmed']);
        self::assertSame('link', $row->fresh()->decisions_json['tenant']['action'], 'the existing contact is used');
    }

    public function test_name_differs_answered_with_a_new_contact_or_another_contact_is_settled(): void
    {
        [$run, $admin, $agency, $branch] = $this->mixedRun();
        $row = $this->rowOf($run, self::UUID_C);

        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['tenant' => ['action' => 'create']])
            ->assertOk()->assertJsonPath('state', 'ready');
        self::assertSame('create', $row->fresh()->decisions_json['tenant']['action']);

        // …and back to a different existing contact.
        $other = $this->contact($agency, $branch, 'ZZ', 'Someone Else', '0735550377');
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['tenant' => ['action' => 'link', 'contact_id' => $other->id]])
            ->assertOk()->assertJsonPath('state', 'ready');
        self::assertSame($other->id, $row->fresh()->decisions_json['tenant']['contact_id']);

        // Nothing to confirm any more once the lease no longer uses the matched contact.
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['confirm' => 'tenant'])->assertStatus(422);
    }

    public function test_a_company_that_matched_a_person_asks_whether_to_create_the_company_with_that_person_as_contact_person(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $ray = $this->contact($agency, $branch, 'Ray', 'Herbert', '0815550444');
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '13 ZZ Stafford Ave', 'Owner' => 'ZZ Exilite 104 CC', 'Owner Phone' => '+27815550444', 'Owner Email' => ''])]);
        $row = $run->rows()->first();

        self::assertSame(['company:owner'], ReosQuestions::needs($row));
        $card = $this->actingAs($admin)->getJson(route('api.v1.rentals.take-on-import.card', $row))->assertOk()->json('card_html');
        self::assertStringContainsString('Owner: create company “ZZ Exilite 104 CC” with Ray Herbert as contact person?', html_entity_decode($card));
        foreach (['Yes', 'Use Ray Herbert as is', 'New contact'] as $button) {
            self::assertStringContainsString($button, $card);
        }

        // Yes → the company is to be created with Ray linked to it.
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['person' => ['party' => 'owner', 'op' => 'convert']])
            ->assertOk()->assertJsonPath('state', 'ready');
        $d = $row->fresh()->decisions_json;
        self::assertSame('create', $d['owner']['action']);
        self::assertSame($ray->id, $d['owner']['people'][0]['contact_id']);

        // "Use Ray as is" on a second copy of the question.
        $row2 = $this->makeRun($admin, [$this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '13 ZZ Stafford Ave', 'Owner' => 'ZZ Exilite 104 CC', 'Owner Phone' => '+27815550444', 'Owner Email' => ''])])->rows()->first();
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row2), ['confirm' => 'owner'])->assertOk()->assertJsonPath('state', 'ready');
        self::assertSame($ray->id, $row2->fresh()->decisions_json['owner']['contact_id']);
    }

    public function test_a_pure_spelling_difference_including_swapped_names_is_not_a_question_and_leaves_a_small_marker(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $this->contact($agency, $branch, 'Nkome', 'Langelihle', '0734034506');
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '13 ZZ Stafford Ave', 'Tenant' => 'Langelihle Nkomo', 'Tenant Phone' => '+27734034506', 'Tenant Email' => ''])]);
        $row = $run->rows()->first();

        self::assertSame('spelling', $row->tenant_match_json[1]['name_flag']);
        self::assertSame(ReosQuestions::READY, ReosQuestions::state($row), 'the existing contact is used without asking');
        self::assertSame('link', $row->decisions_json['tenant']['action']);

        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringContainsString('data-name-flag="spelling"', $html);
        self::assertStringContainsString('spelling differs', $html);
        self::assertStringContainsString('CoreX has “Nkome Langelihle”, reOS has “Langelihle Nkomo”', html_entity_decode($html));
        self::assertStringContainsString('Uses existing property · creates 1, uses 1 existing contact', html_entity_decode($html), 'owner is new, tenant uses the existing contact');
    }

    public function test_several_matching_properties_are_one_button_each_and_exact_duplicates_are_called_out(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $a = $this->property($agency, $branch, $agent, ['street_number' => '3', 'street_name' => 'ZZ Union Street', 'suburb' => 'ZZ Margate']);
        $b = $this->property($agency, $branch, $agent, ['street_number' => '3', 'street_name' => 'ZZ Union Street', 'suburb' => 'ZZ Margate']);   // the same property entered twice
        $c = $this->property($agency, $branch, $agent, ['street_number' => '3', 'street_name' => 'ZZ Union Street', 'suburb' => 'ZZ Shelly Beach', 'beds' => 3]);
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '3 ZZ Union Street'])]);
        $row = $run->rows()->first();

        self::assertSame(['property'], ReosQuestions::needs($row), 'only the property is asked first');

        $card = $this->actingAs($admin)->getJson(route('api.v1.rentals.take-on-import.card', $row))->assertOk()->json('card_html');
        $card = html_entity_decode($card);
        self::assertStringContainsString('Which property is “3 ZZ Union Street”?', $card);
        self::assertSame(3, substr_count($card, 'data-test="property-candidate"'));
        self::assertStringContainsString('ZZ Margate', $card);
        self::assertStringContainsString('ZZ Shelly Beach', $card);
        self::assertSame(2, substr_count($card, 'Same property entered twice in CoreX'), 'both copies are flagged');
        self::assertStringContainsString("also #{$b->id}", $card);
        self::assertStringContainsString("also #{$a->id}", $card);
        self::assertStringContainsString('None — create new', $card);

        // Pick one → the lease is ready (agent matched, contacts created).
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['property_choice' => $c->id])
            ->assertOk()->assertJsonPath('state', 'ready');
        self::assertSame($c->id, (int) $row->fresh()->decisions_json['property']['link_id']);
    }

    public function test_none_of_these_creates_a_new_property_and_then_asks_only_for_a_suburb_unless_the_default_covers_it(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->property($agency, $branch, $agent, ['street_number' => '3', 'street_name' => 'ZZ Union Street', 'suburb' => 'ZZ Margate']);
        $this->property($agency, $branch, $agent, ['street_number' => '3', 'street_name' => 'ZZ Union Street', 'suburb' => 'ZZ Shelly Beach']);
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '3 ZZ Union Street'])]);
        $row = $run->rows()->first();

        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['property_choice' => 'new'])
            ->assertOk()->assertJsonPath('state', 'needs');
        self::assertSame(['suburb'], ReosQuestions::needs($row->fresh()));

        $sub = $this->p24Suburb();
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['suburb_id' => $sub->id])
            ->assertOk()->assertJsonPath('state', 'ready');
    }

    // ── the "for all" defaults ──────────────────────────────────────────────────────────────

    public function test_the_agent_and_suburb_for_all_reach_leases_with_none_of_their_own_and_follow_a_change(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $other = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => 'ZZ Second Agent']);
        $third = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => 'ZZ Third Agent']);
        $run = $this->makeRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A, 'Property Address' => '1 ZZ Nowhere Road']),                       // agent from reOS, no suburb
            $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '2 ZZ Nowhere Road', 'Agent' => 'Home Finders Coastal (100%)']),   // no agent, no suburb
        ]);
        $a = $this->rowOf($run, self::UUID_A);
        $b = $this->rowOf($run, self::UUID_B);
        self::assertSame(['suburb'], ReosQuestions::needs($a));
        self::assertSame(['suburb', 'agent'], ReosQuestions::needs($b));

        $sub = $this->p24Suburb();
        $res = $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.defaults', $run), ['agent_user_id' => $other->id, 'suburb_id' => $sub->id])->assertOk();
        $res->assertJsonPath('summary.ready', 2)->assertJsonPath('summary.needs', 0)->assertJsonPath('defaults.agent_user_id', $other->id);

        $a = $a->fresh(); $b = $b->fresh();
        self::assertSame($agent->id, (int) $a->decisions_json['agent_user_id'], 'an agent that came from reOS is not overwritten');
        self::assertSame($other->id, (int) $b->decisions_json['agent_user_id'], 'a lease with none gets the default');
        self::assertSame('ZZ Testville', $a->decisions_json['property']['suburb']);
        self::assertSame('ZZ Testville', $b->decisions_json['property']['suburb']);

        // A line shows its own agent only when it differs from the default.
        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertSame(1, substr_count($html, 'data-test="row-agent"'));
        self::assertStringContainsString('ZZ Agent Person', substr($html, strpos($html, 'data-test="row-agent"') - 10, 200));

        // The default changes → the lease that took the default follows; the one with its own stays.
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.defaults', $run), ['agent_user_id' => $third->id])->assertOk();
        self::assertSame($third->id, (int) $b->fresh()->decisions_json['agent_user_id']);
        self::assertSame($agent->id, (int) $a->fresh()->decisions_json['agent_user_id']);

        // A choice made on the lease itself is its own: later changes of the default leave it alone.
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $b), ['agent_user_id' => $other->id])->assertOk();
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.defaults', $run), ['agent_user_id' => $agent->id])->assertOk();
        self::assertSame($other->id, (int) $b->fresh()->decisions_json['agent_user_id']);

        // Clearing the suburb default sends the leases that took it back to asking.
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.defaults', $run), ['suburb_id' => null])->assertOk()->assertJsonPath('summary.needs', 2);

        // Only an agent of this agency can be the default; a made-up suburb is refused.
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.defaults', $run), ['agent_user_id' => 999999999])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.defaults', $run), ['suburb_id' => 999999999])->assertStatus(422);
    }

    public function test_the_defaults_reach_a_property_that_was_answered_none_of_these_afterwards(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->property($agency, $branch, $agent, ['street_number' => '3', 'street_name' => 'ZZ Union Street', 'suburb' => 'ZZ Margate']);
        $this->property($agency, $branch, $agent, ['street_number' => '3', 'street_name' => 'ZZ Union Street', 'suburb' => 'ZZ Shelly Beach']);
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '3 ZZ Union Street'])]);
        $row = $run->rows()->first();
        $sub = $this->p24Suburb();
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.defaults', $run), ['suburb_id' => $sub->id])->assertOk();

        self::assertSame(['property'], ReosQuestions::needs($row->fresh()), 'still asks which property — the suburb default is for NEW properties');
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['property_choice' => 'new'])
            ->assertOk()->assertJsonPath('state', 'ready');
        self::assertSame('ZZ Testville', $row->fresh()->decisions_json['property']['suburb'], 'the default suburb is used for the new property');
    }

    public function test_the_agent_list_is_active_real_agents_only(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $mk = fn (string $name, array $o = []) => User::factory()->create(array_merge(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true, 'name' => $name], $o));
        $archived = $mk('ZZ Archived Agent'); $archived->delete();
        $inactive = $mk('ZZ Inactive Agent', ['is_active' => false]);
        $fixture = $mk('ZZ Fixture Agent', ['email' => 'zz-fixture@example.test']);
        $throwaway = $mk('ZZ Throwaway Agent', ['email' => 'zz-overnight@throwaway.test']);
        $local = $mk('ZZ Local Agent', ['email' => 'zz@corexos.local']);
        $assistant = $mk('ZZ Assistant', ['role' => 'assistant']);
        $other = $mk('ZZ Other Agencys Agent');
        $other->forceFill(['agency_id' => Agency::create(['name' => 'ZZ Other Agency', 'slug' => 'zz-' . uniqid()])->id])->save();
        $real = $mk('ZZ Real Second Agent');

        $ids = collect(app(ReosAgentMatcher::class)->users((int) $agency->id))->pluck('id')->all();

        self::assertContains($agent->id, $ids);
        self::assertContains($real->id, $ids);
        foreach ([$archived, $inactive, $fixture, $throwaway, $local, $assistant, $other] as $gone) {
            self::assertNotContains($gone->id, $ids, $gone->name);
        }
    }

    // ── failed, retry, import ───────────────────────────────────────────────────────────────

    public function test_a_failed_lease_says_why_with_a_way_to_fix_it_and_can_be_tried_again(): void
    {
        [$run, $admin] = $this->mixedRun();
        $bad = $this->makeRun($admin, [$this->reosRow(['uuid' => self::UUID_D, 'Lease Status' => 'Nonsense'])]);
        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $bad))->assertOk()->getContent();
        self::assertStringContainsString('data-test="row-failed"', $html);
        self::assertStringContainsString('Lease Status', $html);
        self::assertStringContainsString('Back to batches to upload the corrected file', $html, 'a bad file is fixed in the file');

        // An import attempt that said why not: a "Fix it" and "Try again"; trying again clears the error and the lease is ready again.
        $row = $this->rowOf($run, self::UUID_A);
        $row->forceFill(['import_error' => 'ZZ the property could not be saved'])->save();
        self::assertSame(ReosQuestions::FAILED, ReosQuestions::state($row->fresh()));
        $page = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringContainsString('ZZ the property could not be saved', $page);
        self::assertStringContainsString('data-fix="' . $row->id . '"', $page);
        self::assertStringContainsString('Try again', $page);
        $card = $this->actingAs($admin)->getJson(route('api.v1.rentals.take-on-import.card', $row))->assertOk()->json('card_html');
        self::assertStringContainsString('ZZ the property could not be saved', $card);
        self::assertStringContainsString('data-test="reos-panel"', $card, 'the card carries the Change controls');

        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['retry' => true])->assertOk()->assertJsonPath('state', 'ready');
        self::assertNull($row->fresh()->import_error);
    }

    public function test_import_takes_exactly_the_ready_leases_and_the_imported_line_links_to_the_lease(): void
    {
        [$run, $admin] = $this->mixedRun();
        $this->actingAs($admin);

        $r = app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);
        self::assertSame(1, $r['created'], (string) $run->rows()->whereNotNull('import_error')->value('import_error'));
        self::assertSame(0, $r['remaining']);
        self::assertSame(1, Lease::withoutGlobalScopes()->count(), 'the two leases that need an answer were not touched');

        $s = ReosRunSummary::for($run);
        self::assertSame([1, 0, 2], [$s['imported'], $s['ready'], $s['needs']]);
        $page = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringContainsString('data-test="row-imported"', $page);
        self::assertStringContainsString(route('corex.leases.show', Lease::withoutGlobalScopes()->value('id')), $page);

        // Answer the rest, import again: nothing is created twice.
        $c = $this->rowOf($run, self::UUID_C);
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $c), ['confirm' => 'tenant'])->assertOk();
        $r2 = app(ReosTenancyConfirmService::class)->importChunk($run->fresh(), $admin, true);
        self::assertSame(1, $r2['created']);
        self::assertSame(2, Lease::withoutGlobalScopes()->count());
    }

    public function test_answers_are_saved_as_they_are_given_and_survive_a_reload(): void
    {
        [$run, $admin] = $this->mixedRun();
        $c = $this->rowOf($run, self::UUID_C);
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $c), ['confirm' => 'tenant'])->assertOk();

        $after = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringContainsString('3 leases · 2 ready · 1 need you · 0 imported', $after);
        self::assertSame(2, substr_count($after, 'data-test="reos-line"'));
        self::assertSame([$this->rowOf($run, self::UUID_B)->id], json_decode(html_entity_decode((string) preg_replace('/.*data-queue="([^"]*)".*/s', '$1', $after)), true));
    }

    // ── the deposit ─────────────────────────────────────────────────────────────────────────

    public function test_a_deposit_of_nothing_is_not_stored_or_shown_and_never_overwrites_a_deposit_corex_holds(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);

        // First import carries a real deposit.
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '13 ZZ Stafford Ave', 'Deposit Required' => '8500.00'])]);
        app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);
        $lease = Lease::withoutGlobalScopes()->firstOrFail();
        self::assertSame(8500.0, (float) $lease->deposit_amount);

        // A later file says 0 for it: the batch records what the column held, the line does not print R0, the lease keeps its deposit.
        $run2 = $this->makeRun($admin, [$this->reosRow(['Property Address' => '13 ZZ Stafford Ave', 'Deposit Required' => '0.00'])]);
        self::assertEquals(['blank' => 0, 'zero' => 1, 'amount' => 0], $run2->counts_json['deposit_column']);
        self::assertNull($run2->rows()->first()->payload_json['deposit']);
        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run2))->assertOk()->getContent();
        self::assertStringNotContainsString('R0.00', $html);
        self::assertStringNotContainsString('deposit R0', $html);
        app(ReosTenancyConfirmService::class)->importChunk($run2, $admin, true);
        self::assertSame(8500.0, (float) $lease->fresh()->deposit_amount, 'an empty deposit in reOS does not wipe the one CoreX holds');
        self::assertEqualsCanonicalizing(['blank' => 0, 'zero' => 0, 'amount' => 1], $run->counts_json['deposit_column']);

        // A new lease with a 0 deposit has none recorded rather than R0.00.
        $run3 = $this->makeRun($admin, [$this->reosRow(['uuid' => self::UUID_E, 'Property Address' => '13 ZZ Stafford Ave', 'Deposit Required' => '0.00'])]);
        app(ReosTenancyConfirmService::class)->importChunk($run3, $admin, true);
        self::assertNull(Lease::withoutGlobalScopes()->where('external_id', self::UUID_E)->value('deposit_amount'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    /**
     * Three leases on "13 ZZ Stafford Avenue" (a property CoreX holds): A is ready; B is a new property with no suburb; C matched a
     * contact under another name. Returns [run, admin, agency, branch, agent].
     *
     * @return array{0: RentalTakeOnImportRun, 1: User, 2: Agency, 3: Branch, 4: User}
     */
    private function mixedRun(): array
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $this->contact($agency, $branch, 'Elana', 'Reid', '0635550299');
        $run = $this->makeRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A, 'Property Address' => '13 ZZ Stafford Ave']),
            $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '99 ZZ Nowhere Road']),
            $this->reosRow(['uuid' => self::UUID_C, 'Property Address' => '13 ZZ Stafford Ave', 'Tenant' => 'ZZ TEST Tenant Two', 'Tenant Phone' => '+27635550299', 'Tenant Email' => '']),
        ]);

        return [$run, $admin, $agency, $branch, $agent];
    }

    private function rowOf(RentalTakeOnImportRun $run, string $uuid): RentalTakeOnImportRow
    {
        return RentalTakeOnImportRow::query()->where('run_id', $run->id)->where('external_id', $uuid)->firstOrFail();
    }

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

    /** A property CoreX already holds, with exactly the address fields given. */
    private function property(Agency $agency, Branch $branch, User $agent, array $f): Property
    {
        $p = Property::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'ZZ property ' . uniqid(), 'address' => 'ZZ', 'price' => 0, 'status' => 'let_out', 'listing_type' => 'rental',
            'property_type' => 'house', 'beds' => 0, 'baths' => 0, 'garages' => 0, 'suburb' => 'ZZ Somewhere',
        ], $f));
        Property::withoutGlobalScopes()->whereKey($p->id)->update(array_intersect_key($f, array_flip(['street_number', 'unit_number', 'complex_name'])));

        return $p->fresh();
    }
}
