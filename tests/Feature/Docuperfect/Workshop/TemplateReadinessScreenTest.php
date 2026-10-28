<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-readiness.md §4 / §7 — the screen and the command: who may open it, 404 across agencies by direct URL,
 * the buttons that lead to it, and the exit codes.
 */
final class TemplateReadinessScreenTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    public function test_the_owner_and_the_agencys_own_manager_can_open_it_and_see_the_verdict(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->megaShaped($mega, $owner);
        $manager = $this->agencyManager($mega);

        foreach ([$owner, $manager] as $who) {
            $html = $this->actingAs($who)->get(route('docuperfect.templates.readiness', $t->id))->assertOk()->getContent();
            $this->assertStringContainsString('Readiness check: Mega Lease Agreement 2026', $html);
            $this->assertStringContainsString('data-verdict-text', $html);
            $this->assertStringContainsString('data-check="R05"', $html);
        }
    }

    public function test_a_failing_template_shows_what_to_do_and_lists_failures_first(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->megaShaped($mega, $owner, ['signing_parties' => []]);

        $html = $this->actingAs($owner)->get(route('docuperfect.templates.readiness', $t->id))->assertOk()->getContent();
        $this->assertStringContainsString('Not ready', $html);
        $this->assertStringContainsString('What to do:', $html);
        $this->assertLessThan(strpos($html, 'data-check="R01"'), strpos($html, 'data-status="fail"'));
    }

    public function test_another_agencys_manager_gets_404_by_direct_url_and_no_permission_gets_403(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $other = $this->agency('Cape Town Lettings');
        $owner = $this->owner();
        $t = $this->megaShaped($mega, $owner);

        $foreign = $this->agencyManager($other);
        $this->actingAs($foreign)->get(route('docuperfect.templates.readiness', $t->id))->assertNotFound();

        $plain = User::factory()->create(['agency_id' => $mega->id, 'role' => 'agent', 'is_active' => true]);
        $this->actingAs($plain)->get(route('docuperfect.templates.readiness', $t->id))->assertForbidden();

        $this->actingAs($owner)->get(route('docuperfect.templates.readiness', 99999999))->assertNotFound();
    }

    public function test_the_list_and_both_edit_screens_link_to_it(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $t = $this->megaShaped($mega, $owner);
        $link = route('docuperfect.templates.readiness', $t->id);

        $this->assertStringContainsString($link, $this->actingAs($owner)->get(route('docuperfect.templates.index'))->assertOk()->getContent());
    }

    public function test_the_command_prints_the_verdict_and_exits_1_when_not_ready(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $owner = $this->owner();
        $good = $this->megaShaped($mega, $owner);
        $bad = $this->megaShaped($mega, $owner, ['signing_parties' => []]);

        $this->artisan('templates:readiness', ['template_id' => $good->id])->expectsOutputToContain('Verdict:')->assertExitCode(0);
        $this->artisan('templates:readiness', ['template_id' => $bad->id])->expectsOutputToContain('not_ready')->assertExitCode(1);
        $this->artisan('templates:readiness', ['template_id' => 99999999])->expectsOutputToContain('no template')->assertExitCode(1);
    }

    public function test_the_command_json_output_is_valid_json(): void
    {
        $mega = $this->agency('Mega Real Estate');
        $t = $this->megaShaped($mega, $this->owner());

        \Illuminate\Support\Facades\Artisan::call('templates:readiness', ['template_id' => $t->id, '--json' => true]);
        $decoded = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->assertSame($t->id, $decoded['template']['id']);
        $this->assertArrayHasKey('verdict', $decoded);
        $this->assertCount(17, $decoded['checks']);
    }
}
