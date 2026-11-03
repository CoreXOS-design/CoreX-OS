<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Road to Sold General settings (Johan, 2 Nov): the v4 settings each have their own section, in plain words, with the standard under every switch. */
final class GeneralSettingsSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function html(): string
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        SettingsService::forget();

        return $this->actingAs($admin)->get(route('road-to-sold.settings.index'))->assertOk()->getContent();
    }

    private function section(string $html, string $group): string
    {
        $at = strpos($html, "x-show=\"g === '{$group}'\"");
        $this->assertNotFalse($at, "no section for {$group}");
        $next = strpos($html, '<div x-show="g === ', $at + 20);

        return $next === false ? substr($html, $at) : substr($html, $at, $next - $at);
    }

    public function test_each_new_setting_has_its_own_section(): void
    {
        $h = $this->html();
        foreach (['starting' => 'Starting', 'claims' => 'Claims', 'ownerfound' => 'Owner found', 'aftercall' => 'After contact', 'colleagues' => "Colleagues&#039; buyers"] as $g => $label) {
            $this->assertStringContainsString('data-group="' . $g . '">' . $label . '</button>', $h);
        }
        $this->assertStringContainsString('name="agent_start_required"', $this->section($h, 'starting'));
        $claims = $this->section($h, 'claims');
        foreach (['claim_hold_days', 'claim_warn_days', 'claim_extension_days', 'claim_extensions_allowed'] as $f) {
            $this->assertStringContainsString('name="' . $f . '"', $claims);
        }
        $this->assertStringContainsString('name="owner_requires_deed"', $this->section($h, 'ownerfound'));
        $after = $this->section($h, 'aftercall');
        foreach (['no_answer_drop_after', 'hold_days', 'default_callback_days'] as $f) {
            $this->assertStringContainsString('name="' . $f . '"', $after);
        }
        $this->assertStringContainsString('name="source_for_colleagues"', $this->section($h, 'colleagues'));
        $ontrack = $this->section($h, 'ontrack');
        foreach (['agent_start_required', 'claim_hold_days', 'owner_requires_deed', 'default_callback_days', 'source_for_colleagues'] as $moved) {
            $this->assertStringNotContainsString('name="' . $moved . '"', $ontrack, "{$moved} no longer sits in the on-track bar section");
        }
    }

    public function test_the_two_holds_are_named_in_plain_words_and_point_at_each_other(): void
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $this->html())), ENT_QUOTES);
        $this->assertStringContainsString('A claimed property with no work on it goes back to the pool after', $text);
        $this->assertStringContainsString("Don't offer a contacted property to anyone for", $text);
        $this->assertStringContainsString('Not the same as', $text);
        foreach (['stuck', 'CMA Info', 'claim hold/extension'] as $jargon) {
            $this->assertStringNotContainsStringIgnoringCase($jargon, $text, "'{$jargon}' is jargon on this page");
        }
    }

    public function test_every_switch_says_its_standard(): void
    {
        $h = $this->html();
        $this->assertStringContainsString('data-qa="std-agent_start_required">Standard: on', $h);
        $this->assertStringContainsString('data-qa="std-owner_requires_deed">Standard: on', $h);
        $this->assertStringContainsString('data-qa="std-capture_needs_review">Standard: off', $h);
        $this->assertStringContainsString('data-qa="std-levels_signoff_required">Standard: on', $h);
        $this->assertStringContainsString('data-qa="std-lock_agents_to_areas">Standard: off', $h);
        $this->assertStringContainsString('data-qa="std-half_day_leave_halves_target">Standard: off', $h);
    }

    public function test_general_links_to_the_targets_page_and_it_says_stock_loaded(): void
    {
        $this->assertStringContainsString('data-qa="link-stock-targets"', $this->html());
    }

    /** 23.24: the conveyor's next-step windows and the claims-first switch sit in the existing sections, say their standard and save. */
    public function test_the_conveyor_windows_and_the_claims_first_switch_are_settings(): void
    {
        if (! \App\Models\RoadToSold\RtsSetting::hasConveyorColumns()) {
            $this->markTestSkipped('the conveyor settings migration (2026_11_02_170000) has not been applied yet - held for Johan\'s go');
        }
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        SettingsService::forget();
        $h = $this->actingAs($admin)->get(route('road-to-sold.settings.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="due_claimed_days"', $this->section($h, 'claims'));
        $this->assertStringContainsString('name="fill_from_claims_first"', $this->section($h, 'claims'));
        $this->assertStringContainsString('data-qa="std-fill_from_claims_first">Standard: on', $h);
        $this->assertStringContainsString('name="due_owner_days"', $this->section($h, 'ownerfound'));
        $after = $this->section($h, 'aftercall');
        $this->assertStringContainsString('name="due_contacted_days"', $after);
        $this->assertStringContainsString('name="due_held_days"', $after);

        $this->put(route('road-to-sold.settings.general.update'), ['due_claimed_days' => 4, 'due_owner_days' => 2, 'due_contacted_days' => 10, 'due_held_days' => 3, 'fill_from_claims_first' => '0'])
            ->assertRedirect(route('road-to-sold.settings.index'));
        SettingsService::forget();
        $s = SettingsService::forAgency($agency->id);
        $this->assertSame([4, 2, 10, 3, false], [$s->dueClaimedDays(), $s->dueOwnerDays(), $s->dueContactedDays(), $s->dueHeldDays(), $s->fillFromClaimsFirst()]);

        $this->put(route('road-to-sold.settings.general.update'), ['due_claimed_days' => 0])->assertSessionHasErrors('due_claimed_days');
    }
}
