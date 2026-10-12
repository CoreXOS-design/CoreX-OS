<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedReceipt;
use Illuminate\Support\Facades\DB;

/**
 * AT-450 round 4 - the list: tiles, status ticks, and two collapsible sections (At the agency / Collected) plus Archived when ticked, each
 * with its own count, sort and pagination; search on the named fields, the other filters, scope, CSV that follows the ticks.
 */
final class TitleDeedListTest extends TitleDeedTestCase
{
    private TitleDeed $old;
    private TitleDeed $mid;
    private TitleDeed $new;
    private TitleDeed $done;
    private TitleDeed $done2;

    protected function setUp(): void
    {
        parent::setUp();
        $other = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchB->id, 'role' => 'agent', 'name' => 'Zinhle Cele']);

        $this->old = $this->deed([$this->contact('Nomvula', 'Dube')], ['received_at' => '2026-08-01', '_property' => $this->property('10 Alpha Road', 'Margate'), 'attorney_firm' => 'Cox Yeats']);
        $this->mid = $this->deed([$this->contact('Pieter', 'van der Merwe')], ['received_at' => '2026-09-01', '_property' => $this->property('20 Bravo Road', 'Uvongo'), 'agent_id' => $other->id, 'branch_id' => $this->branchB->id]);
        $this->new = $this->deed([$this->contact('Lerato', 'Khumalo')], ['received_at' => '2026-10-01', '_property' => $this->property('30 Charlie Road', 'Ramsgate')]);
        $this->done = $this->deed([$this->contact('Thandi', 'Mkhize')], ['received_at' => '2026-07-01', '_property' => $this->property('40 Delta Road', 'Shelly Beach')]);
        $this->done->forceFill(['status' => 'collected', 'collected_at' => '2026-07-20 10:00:00', 'collected_by_name' => 'Busisiwe Mkhize'])->save();
        TitleDeedReceipt::create([
            'agency_id' => $this->agencyId, 'title_deed_id' => $this->done->id, 'method' => 'pin', 'collector_type' => 'representative', 'collector_name' => 'Busisiwe Mkhize',
            'recorded_by_user_id' => $this->admin->id,
        ]);
        $this->done2 = $this->deed([$this->contact('Sipho', 'Zulu')], ['received_at' => '2026-06-01', '_property' => $this->property('50 Echo Road', 'Uvongo')]);
        $this->done2->forceFill(['status' => 'collected', 'collected_at' => '2026-08-15 09:30:00', 'collected_by_name' => 'Sipho Zulu'])->save();
        // one deed already told: Owner notified
        $this->mid->forceFill(['status' => 'notified', 'notified_at' => now()->subDays(2)])->save();
    }

    private const NEEDLES = ['10 Alpha', '20 Bravo', '30 Charlie', '40 Delta', '50 Echo'];

    private function html(string $query = ''): string
    {
        return $this->actingAs($this->admin)->get(route('title-deeds.index') . $query)->assertOk()->getContent();
    }

    /** The HTML of one section (agency | collected | archived), or '' when it is not on the page. */
    private function section(string $html, string $key): string
    {
        $start = strpos($html, 'id="td-sec-' . $key . '"');
        if ($start === false) {
            return '';
        }

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    /** The addresses in one section, in the order they appear. */
    private function rows(string $query, string $key): array
    {
        $part = $this->section($this->html($query), $key);
        $found = [];
        foreach (self::NEEDLES as $needle) {
            if (($pos = strpos($part, $needle)) !== false) {
                $found[$pos] = $needle;
            }
        }
        ksort($found);

        return array_values($found);
    }

    private function tile(string $html, string $key): int
    {
        $this->assertSame(1, preg_match('/data-td-tile="' . $key . '".*?data-td-tile-count>([\d,]+)</s', $html, $m), "tile $key present");

        return (int) str_replace(',', '', $m[1]);
    }

    private function header(string $html, string $key): string
    {
        preg_match('/id="td-sec-' . $key . '".*?data-td-section-title>([^<]+)</s', $html, $m);

        return trim($m[1] ?? '');
    }

    private function tileHref(string $html, string $key): string
    {
        preg_match('/<a href="([^"]+)"[^>]*data-td-tile="' . $key . '"/', $html, $m);

        return html_entity_decode($m[1] ?? '');
    }

    /* ═════ Tiles ═════ */

    public function test_the_four_tiles_count_each_kind_in_the_order_given(): void
    {
        $html = $this->html();

        preg_match_all('/data-td-tile="([a-z_]+)"/', $html, $order);
        $this->assertSame(['agency', 'not_told', 'waiting', 'collected'], array_slice($order[1], 0, 4));
        $this->assertSame(3, $this->tile($html, 'agency'), 'received + owner notified: Alpha, Bravo (notified), Charlie');
        $this->assertSame(2, $this->tile($html, 'not_told'), 'Received: Alpha, Charlie');
        $this->assertSame(1, $this->tile($html, 'waiting'), 'Owner notified: Bravo');
        $this->assertSame(2, $this->tile($html, 'collected'));
        foreach (['At the agency', 'Owner not yet told', 'Waiting for collection', 'Collected'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('pstat-v2', $html, 'same tile style as the Properties list');
    }

    public function test_tiles_follow_the_filters_but_not_the_ticks_and_never_count_archived(): void
    {
        $this->assertSame(2, $this->tile($this->html('?show[]=collected'), 'not_told'), 'ticks do not change a tile');
        $this->assertSame(3, $this->tile($this->html('?show[]=archived'), 'agency'));

        $html = $this->html('?agent_id=' . \App\Models\User::where('name', 'Zinhle Cele')->value('id'));
        $this->assertSame(1, $this->tile($html, 'agency'));
        $this->assertSame(0, $this->tile($html, 'collected'));
        $this->assertSame(1, $this->tile($this->html('?search=Delta'), 'collected'));
        $this->assertSame(2, $this->tile($this->html('?from=2026-09-01&to=2026-10-31'), 'agency'));

        $this->mid->delete();
        $this->assertSame(2, $this->tile($this->html(), 'agency'), 'an archived deed is in no tile');
        $this->assertSame(0, $this->tile($this->html(), 'waiting'));
    }

    public function test_a_tile_links_to_its_own_ticks_and_section_and_keeps_the_filters(): void
    {
        $html = $this->html('?search=Road');

        $collected = $this->tileHref($html, 'collected');
        $this->assertStringContainsString('show[0]=collected', urldecode($collected));
        $this->assertStringContainsString('focus=collected', $collected);
        $this->assertStringContainsString('search=Road', $collected, 'the filters survive a tile click');
        $waiting = urldecode($this->tileHref($html, 'waiting'));
        $this->assertStringContainsString('show[0]=waiting', $waiting);
        $this->assertStringNotContainsString('not_told', $waiting);
        $both = urldecode($this->tileHref($html, 'agency'));
        $this->assertStringContainsString('show[0]=not_told', $both);
        $this->assertStringContainsString('show[1]=waiting', $both);
        $this->assertStringContainsString('focus=agency', $both);
    }

    /* ═════ Ticks and sections ═════ */

    public function test_the_default_ticks_show_the_agency_section_only_and_the_collected_section_is_not_on_the_page(): void
    {
        $html = $this->html();

        $this->assertSame('At the agency (3)', $this->header($html, 'agency'));
        $this->assertSame('', $this->section($html, 'collected'), 'Collected is not ticked by default');
        $this->assertSame('', $this->section($html, 'archived'));
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie'], $this->rows('', 'agency'));

        preg_match_all('/name="show\[\]" value="([a-z_]+)"[^>]*checked/', $html, $m);
        $this->assertSame(['not_told', 'waiting'], $m[1], 'the first two are ticked by default');
        $this->assertStringContainsString('Owner not yet told', $html);
        $this->assertStringContainsString('Waiting for collection', $html);
        $this->assertStringNotContainsString('<option value="notified"', $html, 'the Status dropdown is gone');
    }

    public function test_every_tick_combination_shows_the_right_sections_and_rows(): void
    {
        $cases = [
            '?show[]=not_told' => ['agency' => ['10 Alpha', '30 Charlie'], 'collected' => null],
            '?show[]=waiting' => ['agency' => ['20 Bravo'], 'collected' => null],
            '?show[]=not_told&show[]=waiting' => ['agency' => ['10 Alpha', '20 Bravo', '30 Charlie'], 'collected' => null],
            '?show[]=collected' => ['agency' => null, 'collected' => ['40 Delta', '50 Echo']],
            '?show[]=not_told&show[]=collected' => ['agency' => ['10 Alpha', '30 Charlie'], 'collected' => ['40 Delta', '50 Echo']],
            '?show[]=not_told&show[]=waiting&show[]=collected' => ['agency' => ['10 Alpha', '20 Bravo', '30 Charlie'], 'collected' => ['40 Delta', '50 Echo']],
        ];
        foreach ($cases as $query => $expect) {
            foreach ($expect as $key => $rows) {
                if ($rows === null) {
                    $this->assertSame('', $this->section($this->html($query), $key), "$query: no $key section");
                } else {
                    $this->assertEqualsCanonicalizing($rows, $this->rows($query, $key), "$query: $key rows");
                }
            }
        }
    }

    public function test_nothing_ticked_shows_a_sentence_and_is_not_turned_back_into_the_default(): void
    {
        $html = $this->html('?show[]=');

        $this->assertStringContainsString('Tick at least one kind of title deed to see it.', $html);
        $this->assertSame('', $this->section($html, 'agency'));
        $this->assertSame('', $this->section($html, 'collected'));
        $this->assertSame(3, $this->tile($html, 'agency'), 'the tiles still count');
    }

    public function test_an_old_status_bookmark_maps_to_its_tick(): void
    {
        $this->assertSame(['50 Echo', '40 Delta'], $this->rows('?status=collected', 'collected'));
        $this->assertSame('', $this->section($this->html('?status=collected'), 'agency'));
        $this->assertEqualsCanonicalizing(['10 Alpha', '30 Charlie'], $this->rows('?status=received', 'agency'));
        $this->assertSame(['20 Bravo'], $this->rows('?status=notified', 'agency'));
    }

    public function test_headers_carry_their_own_counts_and_collected_has_its_own_columns(): void
    {
        $html = $this->html('?show[]=not_told&show[]=waiting&show[]=collected');
        $this->assertSame('At the agency (3)', $this->header($html, 'agency'));
        $this->assertSame('Collected (2)', $this->header($html, 'collected'));

        $agency = $this->section($html, 'agency');
        $collected = $this->section($html, 'collected');
        $this->assertStringContainsString('Days waiting', $agency);
        $this->assertStringNotContainsString('Days waiting', $collected, 'no Days waiting column in Collected');
        foreach (['Ref', 'Property', 'Owner(s)', 'Agent', 'Received', 'Collected'] as $col) {
            $this->assertStringContainsString('>' . $col, $collected, "collected column $col");
        }
        $this->assertStringContainsString('20 Jul 2026, 10:00', $collected, 'collected date and time');
        $this->assertStringContainsString('Busisiwe Mkhize', $collected, 'who collected');
    }

    public function test_the_default_sorts_are_longest_waiting_first_and_most_recently_collected_first(): void
    {
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie'], $this->rows('', 'agency'), 'longest waiting (earliest received) first');
        $this->assertSame(['50 Echo', '40 Delta'], $this->rows('?show[]=collected', 'collected'), 'most recently collected first (15 Aug before 20 Jul)');
    }

    public function test_each_section_sorts_on_its_own(): void
    {
        $q = '?show[]=not_told&show[]=waiting&show[]=collected';

        $this->assertSame(['30 Charlie', '20 Bravo', '10 Alpha'], $this->rows($q . '&sort_a=received&dir_a=desc', 'agency'));
        $this->assertSame(['50 Echo', '40 Delta'], $this->rows($q . '&sort_a=received&dir_a=desc', 'collected'), 'sorting one section leaves the other alone');
        $this->assertSame(['40 Delta', '50 Echo'], $this->rows($q . '&sort_c=property&dir_c=asc', 'collected'));
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie'], $this->rows($q . '&sort_c=property&dir_c=asc', 'agency'));
        $this->assertSame(['40 Delta', '50 Echo'], $this->rows($q . '&sort_c=collected&dir_c=asc', 'collected'));
        $this->assertSame('20 Bravo', $this->rows($q . '&sort_a=agent&dir_a=desc', 'agency')[0], 'agent: Zinhle Cele sorts last alphabetically');
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie'], $this->rows($q . '&sort_a=waiting&dir_a=desc', 'agency'));
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie'], $this->rows($q . '&sort_a=nonsense&dir_a=sideways', 'agency'), 'an unknown sort falls back to the default');
        $this->assertSame(['30 Charlie', '20 Bravo', '10 Alpha'], $this->rows($q . '&sort_a=ref&dir_a=desc', 'agency'), 'Ref sorts (newest number first)');
    }

    public function test_the_headers_link_with_the_ticks_and_filters_kept(): void
    {
        $html = $this->html('?show[]=collected&search=Road');
        preg_match('/<th[^>]*><a href="([^"]+)"[^>]*>Property/', $this->section($html, 'collected'), $m);
        $href = urldecode(html_entity_decode($m[1] ?? ''));
        $this->assertStringContainsString('sort_c=property', $href);
        $this->assertStringContainsString('search=Road', $href);
        $this->assertStringContainsString('show[0]=collected', $href);
    }

    /* ═════ Archived ═════ */

    public function test_archived_deeds_show_only_when_ticked_in_a_third_section(): void
    {
        $this->mid->delete();

        $this->assertSame(['10 Alpha', '30 Charlie'], $this->rows('', 'agency'), 'an archived deed is out of the agency section');
        $this->assertSame('', $this->section($this->html(), 'archived'));

        $html = $this->html('?show[]=archived');
        $this->assertSame('Archived (1)', $this->header($html, 'archived'));
        $this->assertSame(['20 Bravo'], $this->rows('?show[]=archived', 'archived'));
        $this->assertStringContainsString('Restore this title deed', $html);
        $this->assertStringContainsString('x-cloak', $this->section($html, 'archived'), 'collapsed by default');
        $this->assertSame(['20 Bravo'], $this->rows('?status=archived', 'archived'));
    }

    /* ═════ Search and the other filters ═════ */

    public function test_search_finds_by_property_owner_attorney_and_collector(): void
    {
        $all = '&show[]=not_told&show[]=waiting&show[]=collected';
        $find = fn (string $q) => array_merge($this->rows('?search=' . $q . $all, 'agency'), $this->rows('?search=' . $q . $all, 'collected'));

        $this->assertSame(['20 Bravo'], $find('Bravo'), 'property address');
        $this->assertSame(['30 Charlie'], $find('Khumalo'), 'owner name');
        $this->assertSame(['30 Charlie'], $find('Lerato%20Khumalo'), 'owner full name');
        $this->assertSame(['10 Alpha'], $find('Cox'), 'attorney firm');
        $this->assertSame(['40 Delta'], $find('Busisiwe'), 'collector name');
        $this->assertSame([], $find('Nobody%20Here'));
        $this->assertSame([], $find('%25'), 'a percent sign is searched for literally, it is not a wildcard');
    }

    public function test_the_other_filters_still_work_inside_the_sections(): void
    {
        $agentId = \App\Models\User::where('name', 'Zinhle Cele')->value('id');
        $this->assertSame(['20 Bravo'], $this->rows('?agent_id=' . $agentId, 'agency'));
        $this->assertSame(['20 Bravo'], $this->rows('?branch_id=' . $this->branchB->id, 'agency'));
        $this->assertSame(['20 Bravo', '30 Charlie'], $this->rows('?from=2026-09-01&to=2026-10-31', 'agency'));
        $this->assertSame(['10 Alpha'], $this->rows('?from=2026-08-01&to=2026-08-31', 'agency'));
        $this->assertEqualsCanonicalizing(['40 Delta', '50 Echo'], $this->rows('?show[]=collected&from=2026-06-01&to=2026-07-31', 'collected'), 'the received range applies to Collected too');
    }

    public function test_a_bad_filter_value_is_refused_not_a_500(): void
    {
        $this->actingAs($this->admin)->get(route('title-deeds.index', ['show' => ['bogus']]))->assertSessionHasErrors('show.0');
        $this->actingAs($this->admin)->get(route('title-deeds.index', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
    }

    public function test_days_waiting_counts_for_open_deeds_only(): void
    {
        $days = (int) \Carbon\Carbon::parse('2026-08-01')->diffInDays(now()->startOfDay());
        $this->assertSame($days, $this->old->fresh()->days_waiting);
        $this->assertNull($this->done->fresh()->days_waiting);
        $this->assertStringContainsString('>' . $days . '<', $this->section($this->html(), 'agency'));
    }

    /* ═════ Empty states, pagination, collapse ═════ */

    public function test_each_section_has_its_own_empty_state(): void
    {
        DB::table('title_deeds')->whereIn('id', [$this->done->id, $this->done2->id])->update(['status' => 'received']);
        $html = $this->html('?show[]=not_told&show[]=waiting&show[]=collected');
        $this->assertStringContainsString('No title deeds collected yet.', $this->section($html, 'collected'));
        $this->assertSame('Collected (0)', $this->header($html, 'collected'));

        DB::table('title_deeds')->update(['status' => 'collected']);
        $html = $this->html();
        $this->assertStringContainsString('No title deeds at the agency.', $this->section($html, 'agency'));
        $this->assertSame('At the agency (0)', $this->header($html, 'agency'));

        $this->actingAs($this->admin)->get(route('title-deeds.index', ['search' => 'zzzz']))->assertOk()->assertSee('No title deeds at the agency.');
    }

    public function test_each_section_paginates_separately_and_the_filters_survive_the_page_links(): void
    {
        foreach (['title_deed_owners', 'title_deed_receipts', 'title_deed_audit_log', 'title_deed_events'] as $child) {
            DB::table($child)->delete();
        }
        DB::table('title_deeds')->delete();
        for ($i = 0; $i < 55; $i++) {
            foreach (['received' => 'A', 'collected' => 'C'] as $status => $tag) {
                DB::table('title_deeds')->insert([
                    'agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'agent_id' => $this->agent->id, 'created_by' => $this->admin->id,
                    'property_id' => $this->mid->property_id, 'property_address' => "$tag Row $i Test Street", 'received_at' => '2026-09-01', 'status' => $status,
                    'collected_at' => $status === 'collected' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $q = '?show[]=not_told&show[]=collected&search=Test';
        $html = $this->html($q);
        $this->assertSame(50, substr_count($this->section($html, 'agency'), 'data-row-actions>'));
        $this->assertSame(50, substr_count($this->section($html, 'collected'), 'data-row-actions>'));
        $this->assertSame('At the agency (55)', $this->header($html, 'agency'));
        $this->assertStringContainsString('of 55', $this->section($html, 'agency'));

        $p2 = $this->html($q . '&page_a=2');
        $this->assertSame(5, substr_count($this->section($p2, 'agency'), 'data-row-actions>'));
        $this->assertSame(50, substr_count($this->section($p2, 'collected'), 'data-row-actions>'), 'page 2 of one section leaves the other on its page 1');
        preg_match('/href="([^"]*page_c=2[^"]*)"/', $this->section($p2, 'collected'), $m);
        $link = html_entity_decode($m[1] ?? '');
        $this->assertStringContainsString('search=Test', $link, 'the filters survive pagination');
        $this->assertStringContainsString('page_a=2', $link, 'and the other section keeps its page');
    }

    public function test_collapse_defaults_and_a_chosen_section_is_forced_open(): void
    {
        $all = $this->html('?show[]=not_told&show[]=waiting&show[]=collected');
        $this->assertStringContainsString('tdSectionState(' . $this->admin->id . ", 'agency', true, false)", $this->section($all, 'agency'), 'At the agency is open by default');
        $this->assertStringContainsString('tdSectionState(' . $this->admin->id . ", 'collected', false, false)", $this->section($all, 'collected'), 'Collected is collapsed by default');

        $chosen = $this->section($this->html('?show[]=collected&focus=collected'), 'collected');
        $this->assertStringContainsString("'collected', false, true)", $chosen, 'a tile / tick opens its section');
        $this->assertStringContainsString('scrollIntoView', $chosen);
        $this->assertStringContainsString('td-list-open-', $all, 'the open / closed choice is remembered per user in the browser');
    }

    /* ═════ Scope ═════ */

    public function test_the_tiles_and_sections_only_count_what_the_person_may_open(): void
    {
        $branchB = $this->userWith(['access_title_deeds', 'title_deeds.view'], 'branch', $this->branchB->id);
        $html = $this->actingAs($branchB)->get(route('title-deeds.index', ['show' => ['not_told', 'waiting', 'collected']]))->assertOk()->getContent();

        $this->assertSame(1, $this->tile($html, 'agency'), 'only Bravo is in branch B');
        $this->assertSame(0, $this->tile($html, 'collected'));
        $this->assertSame('At the agency (1)', $this->header($html, 'agency'));
        $this->assertStringNotContainsString('10 Alpha', $html);

        $own = $this->userWith(['access_title_deeds', 'title_deeds.view'], 'own');
        $this->assertSame(0, $this->tile($this->actingAs($own)->get(route('title-deeds.index'))->assertOk()->getContent(), 'agency'), 'own scope: nothing is theirs');

        // another agency never sees any of it (the page may not even exist for an agency without the register)
        $stranger = $this->userWith(['access_title_deeds', 'title_deeds.view'], 'all', null, $this->makeAgency('Cape Town'));
        $page = $this->actingAs($stranger)->get(route('title-deeds.index', ['show' => ['not_told', 'waiting', 'collected']]));
        foreach (self::NEEDLES as $needle) {
            $this->assertStringNotContainsString($needle, $page->getContent());
        }
        if ($page->getStatusCode() === 200) {
            $this->assertSame(0, $this->tile($page->getContent(), 'agency'));
        }
    }

    /* ═════ CSV ═════ */

    public function test_csv_export_exports_what_is_ticked_and_neutralises_formulas(): void
    {
        $this->old->update(['attorney_firm' => '=HYPERLINK("http://evil.test","click")']);

        $default = $this->actingAs($this->admin)->get(route('title-deeds.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('10 Alpha Road', $default);
        $this->assertStringContainsString('20 Bravo Road', $default);
        $this->assertStringNotContainsString('40 Delta Road', $default, 'Collected is not ticked, so it is not exported');
        $this->assertStringContainsString("'=HYPERLINK", $default);
        $this->assertStringNotContainsString(',=HYPERLINK', $default);

        $collected = $this->actingAs($this->admin)->get(route('title-deeds.export', ['show' => ['collected']]))->assertOk()->streamedContent();
        $this->assertStringContainsString('40 Delta Road', $collected);
        $this->assertStringNotContainsString('10 Alpha Road', $collected);

        $order = $this->actingAs($this->admin)->get(route('title-deeds.export', ['show' => ['not_told', 'waiting', 'collected']]))->assertOk()->streamedContent();
        $this->assertLessThan(strpos($order, '50 Echo Road'), strpos($order, '30 Charlie Road'), 'at the agency first, then collected');
        $this->assertLessThan(strpos($order, '40 Delta Road'), strpos($order, '50 Echo Road'), 'collected: most recent first');

        $this->mid->delete();
        $this->assertStringNotContainsString('20 Bravo Road', $this->actingAs($this->admin)->get(route('title-deeds.export'))->streamedContent());
        $withArchived = $this->actingAs($this->admin)->get(route('title-deeds.export', ['show' => ['archived']]))->streamedContent();
        $this->assertStringContainsString('20 Bravo Road', $withArchived);
        $this->assertStringContainsString('Archived', $withArchived);

        $filtered = $this->actingAs($this->admin)->get(route('title-deeds.export', ['show' => ['not_told', 'waiting'], 'search' => 'Charlie']))->streamedContent();
        $this->assertStringContainsString('30 Charlie Road', $filtered);
        $this->assertStringNotContainsString('10 Alpha Road', $filtered, 'the filters apply to the export');
    }

    public function test_the_export_link_carries_the_ticks_and_filters(): void
    {
        $html = $this->html('?show[]=collected&search=Delta');
        preg_match('/href="([^"]+export[^"]*)"/', $html, $m);
        $href = urldecode(html_entity_decode($m[1] ?? ''));
        $this->assertStringContainsString('show[0]=collected', $href);
        $this->assertStringContainsString('search=Delta', $href);
    }
}
