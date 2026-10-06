<?php

namespace Tests\Feature\Platform\Agreement;

use App\Mail\PlatformEsign\AgreementCountersignReminderMail;
use App\Mail\PlatformEsign\AgreementInviteMail;
use App\Models\DevSetting;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Event;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementReminders;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\Agreement\AgreementSettings;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Link expiry + reminders — spec §11.14. Defaults: link 30 days; reminder after 3 days of no progress, then every 3 days,
 * at most 3; RR reminded after 1 day awaiting countersign. Reminders stop the moment the state moves on.
 */
class RemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-10-06 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel', 'email' => 'owner@throwaway.invalid']);
    }

    private function sent(User $owner): Document
    {
        Mail::fake();

        return app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@throwaway.invalid'], $owner->id);
    }

    private function runAt(string $when, bool $dry = false): array
    {
        Carbon::setTestNow($when);

        return app(AgreementReminders::class)->run($dry);
    }

    private function invites(): int
    {
        return Mail::sent(AgreementInviteMail::class, fn ($m) => $m->reminder)->count();
    }

    public function test_defaults_are_the_agreed_ones_and_nothing_is_hardcoded(): void
    {
        $this->assertSame(['expiry_days' => 30, 'reminder_days' => 3, 'reminder_repeat_days' => 3, 'reminder_max' => 3, 'countersign_reminder_days' => 1], AgreementSettings::all());
        $doc = $this->sent($this->owner());
        $this->assertSame('2026-11-05', $doc->expires_at->toDateString());
        DevSetting::set(AgreementService::EXPIRY_KEY, '10');
        Cache::flush();
        $this->assertSame(10, AgreementSettings::get('expiry_days'));
    }

    public function test_the_agency_is_reminded_after_three_days_then_every_three_days_at_most_three_times(): void
    {
        $doc = $this->sent($this->owner());

        $this->runAt('2026-10-08 08:59:00');                        // 2 days 23h 59m — not yet
        $this->assertSame(0, $this->invites());
        $r = $this->runAt('2026-10-09 09:00:00');                   // day 3
        $this->assertSame(1, $r['reminded']);
        $this->assertSame(1, $this->invites());
        $this->runAt('2026-10-11 09:00:00');                        // day 5 — not yet (every 3)
        $this->assertSame(1, $this->invites());
        $this->runAt('2026-10-12 09:00:00');                        // day 6
        $this->assertSame(2, $this->invites());
        $this->runAt('2026-10-15 09:00:00');                        // day 9
        $this->assertSame(3, $this->invites());
        $this->runAt('2026-10-18 09:00:00');                        // day 12 — capped at 3
        $this->runAt('2026-10-30 09:00:00');
        $this->assertSame(3, $this->invites());
        $this->assertSame(3, Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('reminders_sent'));
        $this->assertSame(3, Event::where('document_id', $doc->id)->where('event', 'reminded')->count());
    }

    public function test_progress_by_the_agency_restarts_the_clock(): void
    {
        $doc = $this->sent($this->owner());
        Carbon::setTestNow('2026-10-08 12:00:00');
        $signer = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first();
        app(AgreementService::class)->save($doc, $signer, ['registered_name' => 'Caprivi Realty (Pty) Ltd'], 0);   // progress on day 2

        $this->runAt('2026-10-09 09:00:00');                        // day 3 since sending, but only 21h since progress
        $this->assertSame(0, $this->invites());
        $this->runAt('2026-10-11 12:00:00');                        // 3 days after the save
        $this->assertSame(1, $this->invites());
    }

    public function test_merely_opening_the_link_is_not_progress(): void
    {
        $doc = $this->sent($this->owner());
        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->get(route('platform-esign.agreement.show', Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token')))->assertOk();
        $this->runAt('2026-10-09 09:00:00');
        $this->assertSame(1, $this->invites());
    }

    public function test_reminders_stop_the_moment_the_state_moves_on(): void
    {
        $owner = $this->owner();
        foreach (['awaiting_countersign', 'completed', 'voided', 'declined', 'expired'] as $status) {
            $doc = $this->sent($owner);
            $doc->update(['status' => $status]);
            if ($status === 'awaiting_countersign') {
                Signer::where('document_id', $doc->id)->where('role_key', 'r1')->update(['status' => 'signed', 'signed_at' => now()]);
            }
        }
        $archived = $this->sent($owner);
        $archived->delete();

        $r = $this->runAt('2026-10-10 09:00:00');                   // day 4: every one of these would otherwise be due
        $this->assertSame(0, $r['reminded'], 'no agency reminder for a signed, voided, declined, expired or archived agreement');
        $this->assertSame(0, $this->invites());
    }

    public function test_expiry_marks_unsigned_agreements_expired_and_never_one_the_agency_already_signed(): void
    {
        $owner = $this->owner();
        $open = $this->sent($owner);
        $partly = $this->sent($owner);
        $partly->update(['status' => 'in_progress', 'form_rev' => 2]);
        $signed = $this->sent($owner);
        $signed->update(['status' => 'awaiting_countersign']);
        Signer::where('document_id', $signed->id)->where('role_key', 'r1')->update(['status' => 'signed', 'signed_at' => now()]);

        $r = $this->runAt('2026-11-06 09:00:00');                   // the day after the 30-day link ends
        $this->assertSame(2, $r['expired']);
        $this->assertSame('expired', $open->fresh()->status);
        $this->assertSame('expired', $partly->fresh()->status);
        $this->assertSame('awaiting_countersign', $signed->fresh()->status);
        $this->assertSame(1, Event::where('document_id', $open->id)->where('event', 'expired')->count());
        $this->assertSame(0, $this->invites(), 'an expired link is not reminded');

        // The agency that already signed is told it has signed, never that the link expired.
        $this->get(route('platform-esign.agreement.show', Signer::where('document_id', $signed->id)->where('role_key', 'r1')->value('token')))
            ->assertOk()->assertSee('You have signed this agreement')->assertDontSee('expired');
        $this->assertSame('awaiting_countersign', $signed->fresh()->status);
    }

    public function test_rr_is_reminded_after_a_day_awaiting_countersign_then_daily_up_to_the_cap_and_never_after_countersign(): void
    {
        $owner = $this->owner();
        $doc = $this->sent($owner);
        Carbon::setTestNow('2026-10-06 10:00:00');
        $doc->update(['status' => 'awaiting_countersign']);
        Signer::where('document_id', $doc->id)->where('role_key', 'r1')->update(['status' => 'signed', 'signed_at' => now()]);

        $this->runAt('2026-10-07 09:59:00');
        Mail::assertNotSent(AgreementCountersignReminderMail::class);
        $r = $this->runAt('2026-10-07 10:00:00');
        $this->assertSame(1, $r['countersign_reminded']);
        Mail::assertSent(AgreementCountersignReminderMail::class, fn ($m) => $m->hasTo('owner@throwaway.invalid') && $m->daysWaiting === 1 && str_contains($m->url, '/countersign'));
        $this->runAt('2026-10-07 18:00:00');                        // same day — no second one
        Mail::assertSent(AgreementCountersignReminderMail::class, 1);
        $this->runAt('2026-10-08 10:00:00');
        $this->runAt('2026-10-09 10:00:00');
        $this->runAt('2026-10-10 10:00:00');                        // capped at 3
        Mail::assertSent(AgreementCountersignReminderMail::class, 3);

        // A fresh agreement that RR then countersigns is never reminded.
        $done = app(AgreementService::class)->send(['name' => 'Sam Signer', 'email' => 'sam@throwaway.invalid'], $owner->id);
        $done->update(['status' => 'awaiting_countersign']);
        Signer::where('document_id', $done->id)->where('role_key', 'r1')->update(['status' => 'signed', 'signed_at' => now()]);
        $done->update(['status' => 'completed']);
        $this->runAt('2026-10-20 10:00:00');
        Mail::assertSent(AgreementCountersignReminderMail::class, 3);
    }

    public function test_the_reminder_mail_never_carries_entered_values(): void
    {
        $owner = $this->owner();
        $doc = $this->sent($owner);
        $doc->update(['form_data' => ['da_account' => '62123456789', 'registered_name' => 'SECRET-NAME-PTY'], 'status' => 'awaiting_countersign']);
        Signer::where('document_id', $doc->id)->where('role_key', 'r1')->update(['status' => 'signed', 'signed_at' => now()]);
        $this->runAt('2026-10-08 10:00:00');
        $html = (new AgreementCountersignReminderMail($doc->fresh(['agency', 'signers']), 2))->render();
        $this->assertStringNotContainsString('62123456789', $html);
        $this->assertStringNotContainsString('SECRET-NAME-PTY', $html);
    }

    public function test_settings_change_the_behaviour_and_zero_switches_reminders_off(): void
    {
        $owner = $this->owner();
        $doc = $this->sent($owner);
        AgreementSettings::save(['expiry_days' => 30, 'reminder_days' => 1, 'reminder_repeat_days' => 1, 'reminder_max' => 0, 'countersign_reminder_days' => 1]);
        Cache::flush();
        $this->runAt('2026-10-10 09:00:00');
        $this->assertSame(0, $this->invites(), 'reminder_max 0 = off');

        AgreementSettings::save(['expiry_days' => 30, 'reminder_days' => 1, 'reminder_repeat_days' => 1, 'reminder_max' => 2, 'countersign_reminder_days' => 1]);
        Cache::flush();
        $this->runAt('2026-10-10 09:00:00');
        $this->assertSame(1, $this->invites());
        $this->runAt('2026-10-11 09:00:00');
        $this->runAt('2026-10-12 09:00:00');
        $this->assertSame(2, $this->invites());
    }

    public function test_resend_resets_the_reminder_clock_and_the_count(): void
    {
        $owner = $this->owner();
        $doc = $this->sent($owner);
        $this->runAt('2026-10-09 09:00:00');
        $this->runAt('2026-10-12 09:00:00');
        $this->assertSame(2, $this->invites());
        Carbon::setTestNow('2026-10-13 09:00:00');
        app(AgreementService::class)->resend($doc->fresh(), $owner->id);
        $s = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first();
        $this->assertSame(0, (int) $s->reminders_sent);
        $this->assertNull($s->last_reminded_at);
        $before = $this->invites();
        $this->runAt('2026-10-15 09:00:00');                         // 2 days after the re-issue — not due
        $this->assertSame($before, $this->invites());
        $this->runAt('2026-10-16 09:00:00');                         // 3 days after
        $this->assertSame($before + 1, $this->invites());
    }

    public function test_a_dry_run_lists_but_changes_and_sends_nothing(): void
    {
        $doc = $this->sent($this->owner());
        $r = $this->runAt('2026-10-09 09:00:00', true);
        $this->assertSame(1, $r['reminded']);
        $this->assertStringContainsString('would remind pat@throwaway.invalid', implode("\n", $r['lines']));
        $this->assertSame(0, $this->invites());
        $this->assertSame(0, (int) Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('reminders_sent'));
        $r = $this->runAt('2026-12-01 09:00:00', true);
        $this->assertSame(1, $r['expired']);
        $this->assertSame('sent', $doc->fresh()->status);
    }

    public function test_the_command_exists_and_is_scheduled_hourly(): void
    {
        $this->assertArrayHasKey('platform-esign:remind-agreements', Artisan::all());
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'platform-esign:remind-agreements'));
        $this->assertNotNull($event, 'the reminder command must be on the schedule');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->artisan('platform-esign:remind-agreements', ['--dry-run' => true])->expectsOutputToContain('[dry run]')->assertSuccessful();
    }
}
