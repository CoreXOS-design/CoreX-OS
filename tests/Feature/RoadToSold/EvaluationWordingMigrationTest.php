<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Services\RoadToSold\OutcomeDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan's rule: CoreX says "evaluation", never "valuation". The rename migration re-words what Road to Sold already stored.
 * Paths proven: the seeded outcome gets the new label and key where the agency kept the seeded wording; an agency that renamed it
 * itself keeps its own label (the key still moves); the call log, the saved summary-bar steps, the settings audit trail, the history
 * note on the owner contact and the calendar appointment title are re-worded; a second run changes nothing; another agency's own
 * outcome that already owns the new key is left alone; the seeded list for a NEW agency already says evaluation.
 */
final class EvaluationWordingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function agency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId(['name' => 'T ' . Str::random(5), 'slug' => 't-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function runMigration(): void
    {
        $m = require base_path('database/migrations/2026_10_29_600000_rename_valuation_to_evaluation_in_road_to_sold.php');
        $m->up();
    }

    private function def(int $agencyId, string $key): ?object
    {
        return DB::table('rts_outcome_definitions')->where('agency_id', $agencyId)->where('key', $key)->first();
    }

    private function oldDefinition(int $agencyId, string $label): void
    {
        DB::table('rts_outcome_definitions')->where('agency_id', $agencyId)->where('key', 'spoke_evaluation_booked')
            ->update(['key' => 'spoke_valuation_booked', 'label' => $label]);
    }

    public function test_a_new_agency_is_seeded_with_the_new_wording(): void
    {
        OutcomeDefinitionService::forgetMemo();
        $a = $this->agency();
        app(OutcomeDefinitionService::class)->ensureDefaults($a, 'sales');

        $row = $this->def($a, 'spoke_evaluation_booked');
        $this->assertNotNull($row);
        $this->assertSame('Spoke - evaluation booked', $row->label);
        $this->assertNull($this->def($a, 'spoke_valuation_booked'));
    }

    public function test_existing_rows_are_reworded_and_an_agencys_own_label_is_kept(): void
    {
        OutcomeDefinitionService::forgetMemo();
        $kept = $this->agency();
        $renamed = $this->agency();
        $svc = app(OutcomeDefinitionService::class);
        $svc->ensureDefaults($kept, 'sales');
        $svc->ensureDefaults($renamed, 'sales');
        $this->oldDefinition($kept, 'Spoke - valuation booked');          // as seeded before today
        $this->oldDefinition($renamed, 'Booked a valuation visit');      // the agency's own wording

        // an agency's saved summary-bar steps + the audit trail that recorded them
        DB::table('rts_settings')->insert(['agency_id' => $kept, 'track' => 'sales', 'created_at' => now(), 'updated_at' => now(),
            'summary_steps' => json_encode([['key' => 'calls_made', 'enabled' => true], ['key' => 'valuations_booked', 'enabled' => true], ['key' => 'valuations_done', 'enabled' => false]])]);
        DB::table('rts_settings_audit')->insert(['agency_id' => $kept, 'track' => 'sales', 'changed_at' => now(),
            'old_values' => json_encode(['summary_steps' => [['key' => 'valuations_booked', 'enabled' => true]]]), 'new_values' => json_encode(['summary_steps' => [['key' => 'valuations_booked', 'enabled' => false]]])]);

        // the call log, the history note on the owner contact, the calendar appointment
        Schema::disableForeignKeyConstraints();
        $user = (int) DB::table('users')->insertGetId(['name' => 'A', 'email' => Str::random(8) . '@example.test', 'password' => 'x', 'agency_id' => $kept, 'branch_id' => $kept, 'role' => 'agent', 'created_at' => now(), 'updated_at' => now()]);
        $eventId = (int) DB::table('calendar_events')->insertGetId(['agency_id' => $kept, 'user_id' => $user, 'title' => 'Valuation: 14 Marine Drive', 'category' => 'property_evaluation', 'event_type' => 'manual', 'event_date' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $mine = DB::table('calendar_events')->insertGetId(['agency_id' => $kept, 'user_id' => $user, 'title' => 'Valuation: not from Road to Sold', 'category' => 'property_evaluation', 'event_type' => 'manual', 'event_date' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $outcomeId = (int) DB::table('rts_contact_outcomes')->insertGetId([
            'agency_id' => $kept, 'track' => 'sales', 'user_id' => $user, 'tracked_property_id' => 1, 'outcome_key' => 'spoke_valuation_booked',
            'outcome_label' => 'Spoke - valuation booked', 'kind' => 'conversation', 'valuation_booked' => true, 'calendar_event_id' => $eventId,
            'next_action' => 'calendar_appointment', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $other = (int) DB::table('rts_contact_outcomes')->insertGetId([
            'agency_id' => $renamed, 'track' => 'sales', 'user_id' => $user, 'tracked_property_id' => 1, 'outcome_key' => 'spoke_valuation_booked',
            'outcome_label' => 'Booked a valuation visit', 'kind' => 'conversation', 'valuation_booked' => true,
            'next_action' => 'calendar_appointment', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contact = (int) DB::table('contacts')->insertGetId(['agency_id' => $kept, 'branch_id' => $kept, 'first_name' => 'O', 'last_name' => 'Wner', 'created_at' => now(), 'updated_at' => now()]);
        $note = (int) DB::table('contact_notes')->insertGetId(['agency_id' => $kept, 'contact_id' => $contact, 'user_id' => $user, 'body' => 'Road to Sold - Spoke - valuation booked: she wants a valuation soon', 'created_at' => now(), 'updated_at' => now()]);
        Schema::enableForeignKeyConstraints();

        $this->runMigration();

        $this->assertSame('Spoke - evaluation booked', $this->def($kept, 'spoke_evaluation_booked')->label, 'seeded wording follows');
        $this->assertNull($this->def($kept, 'spoke_valuation_booked'));
        $this->assertSame('Booked a valuation visit', $this->def($renamed, 'spoke_evaluation_booked')->label, "the agency's own label is kept");

        $this->assertSame('spoke_evaluation_booked', DB::table('rts_contact_outcomes')->where('id', $outcomeId)->value('outcome_key'));
        $this->assertSame('Spoke - evaluation booked', DB::table('rts_contact_outcomes')->where('id', $outcomeId)->value('outcome_label'));
        $this->assertSame('Booked a valuation visit', DB::table('rts_contact_outcomes')->where('id', $other)->value('outcome_label'), 'a custom snapshot label is not touched');
        $this->assertTrue((bool) DB::table('rts_contact_outcomes')->where('id', $outcomeId)->value('valuation_booked'), 'the column is untouched');

        $steps = (string) DB::table('rts_settings')->where('agency_id', $kept)->value('summary_steps');
        $this->assertStringContainsString('evaluations_booked', $steps);
        $this->assertStringContainsString('evaluations_done', $steps);
        $this->assertDoesNotMatchRegularExpression('/(?<![eE])valuations_/', $steps);
        $audit = DB::table('rts_settings_audit')->where('agency_id', $kept)->first();
        $this->assertDoesNotMatchRegularExpression('/(?<![eE])valuations_/', (string) $audit->old_values . (string) $audit->new_values);
        $this->assertStringNotContainsString('eevaluations', $steps . $audit->old_values . $audit->new_values);

        $this->assertSame('Evaluation: 14 Marine Drive', DB::table('calendar_events')->where('id', $eventId)->value('title'));
        $this->assertSame('Valuation: not from Road to Sold', DB::table('calendar_events')->where('id', $mine)->value('title'), 'only appointments Road to Sold created are re-titled');
        $this->assertSame('Road to Sold - Spoke - evaluation booked: she wants a valuation soon', DB::table('contact_notes')->where('id', $note)->value('body'), "the agent's own words are never edited");

        // idempotent: a second run changes nothing
        $stepsBefore = (string) DB::table('rts_settings')->where('agency_id', $kept)->value('summary_steps');
        $before = [DB::table('rts_outcome_definitions')->orderBy('id')->get()->toJson(), DB::table('rts_contact_outcomes')->orderBy('id')->get()->toJson(), DB::table('contact_notes')->orderBy('id')->get()->toJson(), DB::table('calendar_events')->orderBy('id')->get()->toJson()];
        $this->runMigration();
        $after = [DB::table('rts_outcome_definitions')->orderBy('id')->get()->toJson(), DB::table('rts_contact_outcomes')->orderBy('id')->get()->toJson(), DB::table('contact_notes')->orderBy('id')->get()->toJson(), DB::table('calendar_events')->orderBy('id')->get()->toJson()];
        $this->assertSame($before, $after);
        $this->assertSame($stepsBefore, (string) DB::table('rts_settings')->where('agency_id', $kept)->value('summary_steps'), 'a second run leaves the saved steps exactly as they were');
    }

    public function test_an_agency_whose_own_outcome_already_owns_the_new_key_is_left_alone(): void
    {
        OutcomeDefinitionService::forgetMemo();
        $a = $this->agency();
        app(OutcomeDefinitionService::class)->ensureDefaults($a, 'sales');
        // a leftover old-key row beside the new-key row (cannot both be renamed)
        $keep = DB::table('rts_outcome_definitions')->where('agency_id', $a)->where('key', 'spoke_evaluation_booked')->first();
        $copy = (array) $keep;
        unset($copy['id']);
        $copy['key'] = 'spoke_valuation_booked';
        $copy['label'] = 'Spoke - valuation booked';
        DB::table('rts_outcome_definitions')->insert($copy);

        $this->runMigration();

        $this->assertSame(1, DB::table('rts_outcome_definitions')->where('agency_id', $a)->where('key', 'spoke_evaluation_booked')->count());
        $this->assertSame(1, DB::table('rts_outcome_definitions')->where('agency_id', $a)->where('key', 'spoke_valuation_booked')->count(), 'not forced into a duplicate key');
    }
}
