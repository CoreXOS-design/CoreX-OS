<?php

declare(strict_types=1);

namespace Tests\Feature\Tours;

use App\Models\User;
use App\Models\UserTourProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-371 (#18) — per-step guided-tour persistence. Each step a user sees is recorded server-side,
 * keyed by its STABLE step key (the step's element selector), so a completed step never re-triggers
 * — a page/section update resumes from the first un-seen step instead of restarting the whole tour,
 * and the key is content-stable so it survives a deploy / version bump (the def can reorder steps).
 */
final class TourStepPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const TOUR = 'contact-capture'; // a real TourRegistry key
    private const URL  = '/api/v1/tours/' . self::TOUR . '/step';

    private function user(): User
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
    }

    public function test_recording_a_step_persists_it_keyed_by_stable_selector(): void
    {
        $u = $this->user();

        $this->actingAs($u)->postJson(self::URL, ['step' => '[data-tour="contact-add-btn"]'])
            ->assertOk()
            ->assertJsonPath('completed_steps.0', '[data-tour="contact-add-btn"]');

        $p = UserTourProgress::where('user_id', $u->id)->where('tour_key', self::TOUR)->firstOrFail();
        $this->assertSame(['[data-tour="contact-add-btn"]'], $p->completed_steps);
    }

    public function test_step_recording_is_idempotent_and_appends(): void
    {
        $u = $this->user();

        $this->actingAs($u)->postJson(self::URL, ['step' => 'step-a'])->assertOk();
        $this->actingAs($u)->postJson(self::URL, ['step' => 'step-a'])->assertOk(); // duplicate — no-op
        $this->actingAs($u)->postJson(self::URL, ['step' => 'step-b'])->assertOk();

        $p = UserTourProgress::where('user_id', $u->id)->where('tour_key', self::TOUR)->firstOrFail();
        $this->assertSame(['step-a', 'step-b'], $p->completed_steps);
    }

    public function test_completed_step_survives_reload_from_db_the_server_source_of_truth(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson(self::URL, ['step' => '[data-tour="contact-type"]'])->assertOk();

        // A "version bump / cache clear" doesn't touch the DB — the step stays completed on re-read.
        $fresh = UserTourProgress::where('user_id', $u->id)->where('tour_key', self::TOUR)->firstOrFail();
        $this->assertContains('[data-tour="contact-type"]', $fresh->completed_steps);
    }

    public function test_step_write_is_self_scoped_and_does_not_leak_across_users(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->actingAs($a)->postJson(self::URL, ['step' => 'only-a'])->assertOk();

        $this->assertNull(UserTourProgress::where('user_id', $b->id)->where('tour_key', self::TOUR)->first());
    }

    public function test_unknown_tour_key_rejected(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson('/api/v1/tours/not-a-real-tour/step', ['step' => 'x'])->assertStatus(404);
    }
}
