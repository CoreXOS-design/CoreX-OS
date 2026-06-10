<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\User;
use App\Services\AI\Intents\ScheduleEventIntentHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression guard for the "11 o'clock booked at 09:00" incident
 * (event 5741). The LLM expresses a spoken local time, but may emit it
 * as UTC ("09:00Z" == 11:00 SAST). Eloquent stores a Carbon's own
 * wall-clock without converting, so the handler MUST normalize to the app
 * timezone before persisting — otherwise a UTC-expressed time lands two
 * hours early.
 */
final class EllieVoiceTimezoneTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('equivalentInstants')]
    public function test_spoken_11am_is_stored_as_11am_regardless_of_offset(string $datetime): void
    {
        // App timezone is Africa/Johannesburg (UTC+2) — the value under test.
        $this->assertSame('Africa/Johannesburg', config('app.timezone'));

        $user = $this->seedUser();

        $result = app(ScheduleEventIntentHandler::class)->handle(
            ['datetime' => $datetime, 'title' => 'Fetch keys', 'duration_minutes' => 60],
            $user,
            'fetch the keys at 15 Margate News tomorrow at 11',
        );

        // All three strings denote the same instant: 09:00 UTC == 11:00 SAST.
        $this->assertSame('11:00', $result['event']->event_date->format('H:i'));
        $this->assertSame('Africa/Johannesburg', $result['event']->event_date->getTimezone()->getName());
    }

    public static function equivalentInstants(): array
    {
        return [
            'UTC with Z'        => ['2026-06-11T09:00:00Z'],
            'UTC with offset'   => ['2026-06-11T09:00:00+00:00'],
            'local with offset' => ['2026-06-11T11:00:00+02:00'],
        ];
    }

    private function seedUser(): User
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
    }
}
