<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Mail\CommandCenter\CalendarDailyDigest;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserNotificationPreference;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contact birthdays must NOT email one message per birthday (inbox flood — the
 * same failure class as the TaskDueDigest "Cindy Pietersen, 129 unread" case).
 * Every opted-in birthday for the day rolls up into the ONE daily digest email
 * per user (SendCalendarDigests), never a per-contact email/in-app/push.
 */
final class BirthdayDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_many_birthdays_today_produce_one_digest_not_one_email_each(): void
    {
        Mail::fake();

        [$agencyId, $agent] = $this->seedAgencyAgent();
        $this->makeBirthdayType();
        $this->enablePref($agent, 'contact.birthday');

        // Three contacts the agent owns, all with a birthday TODAY, all opted in.
        $this->makeBirthdayContact($agencyId, $agent->id, now(), true, 'Jane', 'Doe');
        $this->makeBirthdayContact($agencyId, $agent->id, now(), true, 'Sam', 'Smith');
        $this->makeBirthdayContact($agencyId, $agent->id, now(), true, 'Amy', 'Lee');

        $this->artisan('corex:calendar:send-digests')->assertExitCode(0);

        // Exactly ONE digest, carrying all three birthdays.
        Mail::assertSent(CalendarDailyDigest::class, 1);
        Mail::assertSent(CalendarDailyDigest::class, fn (CalendarDailyDigest $m) =>
            $m->hasTo($agent->email) && $m->birthdayCount === 3);
    }

    public function test_birthday_owner_without_a_digest_role_still_receives_the_digest(): void
    {
        // No CalendarEventClassSetting exists, so the role-gated calendar digest
        // reaches nobody. A plain agent who owns a birthday contact must still be
        // widened into the recipient set on the strength of the birthday alone.
        Mail::fake();

        [$agencyId, $agent] = $this->seedAgencyAgent();
        $this->makeBirthdayType();
        $this->enablePref($agent, 'contact.birthday');
        $this->makeBirthdayContact($agencyId, $agent->id, now(), true, 'Jane', 'Doe');

        $this->artisan('corex:calendar:send-digests')->assertExitCode(0);

        Mail::assertSent(CalendarDailyDigest::class, fn (CalendarDailyDigest $m) =>
            $m->hasTo($agent->email) && $m->birthdayCount === 1);
    }

    public function test_opted_out_and_non_today_birthdays_are_excluded(): void
    {
        Mail::fake();

        [$agencyId, $agent] = $this->seedAgencyAgent();
        $this->makeBirthdayType();
        $this->enablePref($agent, 'contact.birthday');

        $this->makeBirthdayContact($agencyId, $agent->id, now(), true, 'Today', 'OptedIn');
        $this->makeBirthdayContact($agencyId, $agent->id, now(), false, 'Today', 'OptedOut');
        $this->makeBirthdayContact($agencyId, $agent->id, now()->addDays(3), true, 'NotToday', 'OptedIn');

        $this->artisan('corex:calendar:send-digests')->assertExitCode(0);

        Mail::assertSent(CalendarDailyDigest::class, fn (CalendarDailyDigest $m) =>
            $m->hasTo($agent->email) && $m->birthdayCount === 1);
    }

    public function test_user_who_disabled_the_birthday_preference_gets_no_digest(): void
    {
        Mail::fake();

        [$agencyId, $agent] = $this->seedAgencyAgent();
        $this->makeBirthdayType();
        $this->disablePref($agent, 'contact.birthday');
        $this->makeBirthdayContact($agencyId, $agent->id, now(), true, 'Jane', 'Doe');

        $this->artisan('corex:calendar:send-digests')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgencyAgent(): array
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
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        return [$agencyId, $user];
    }

    private function makeBirthdayContact(int $agencyId, int $ownerId, $birthday, bool $reminder, string $first, string $last): int
    {
        $c = new Contact();
        $c->forceFill([
            'first_name'         => $first,
            'last_name'          => $last . ' ' . Str::random(4),
            'phone'              => '0820000000',
            'created_by_user_id' => $ownerId,
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            // Same month/day as the given date so the digest's date predicate matches.
            'birthday'           => $birthday->copy()->subYears(30)->format('Y-m-d'),
            'birthday_reminder'  => $reminder,
        ])->save();

        return (int) $c->id;
    }

    private function makeBirthdayType(): NotificationEventType
    {
        return NotificationEventType::create([
            'key'               => 'contact.birthday',
            'pillar'            => 'contact',
            'group_label'       => 'Activity',
            'label'             => 'Contact birthday today',
            'description'       => "Today is this contact's birthday.",
            'default_enabled'   => true,
            'threshold_unit'    => 'none',
            'default_threshold' => null,
            'threshold_min'     => null,
            'threshold_max'     => null,
            'supports_in_app'   => true,
            'supports_email'    => true,
            'supports_push'     => true,
            'is_adapter'        => false,
            'adapter_column'    => null,
            'sort_order'        => 1,
        ]);
    }

    private function enablePref(User $user, string $key): void
    {
        $this->setPref($user, $key, true);
    }

    private function disablePref(User $user, string $key): void
    {
        $this->setPref($user, $key, false);
    }

    private function setPref(User $user, string $key, bool $enabled): void
    {
        $type = NotificationEventType::where('key', $key)->firstOrFail();
        UserNotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'notification_event_type_id' => $type->id],
            ['enabled' => $enabled, 'threshold' => 1, 'channel_in_app' => true, 'channel_email' => false, 'channel_push' => true]
        );
    }
}
