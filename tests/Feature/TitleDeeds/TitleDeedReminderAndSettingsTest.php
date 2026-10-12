<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Mail\TitleDeeds\TitleDeedNoticeMail;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Services\TitleDeeds\TitleDeedNotifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * AT-450 - the reminder command (due / not due / capped / idempotent / dry run / per-agency settings) and the settings saver
 * (defaults, validation, has()-guarding, permission, the wizard control map).
 */
final class TitleDeedReminderAndSettingsTest extends TitleDeedTestCase
{
    private function notifiedDeed(?int $daysAgo = 15): TitleDeed
    {
        $deed = $this->deed([$this->contact('Thandi', 'Mkhize')]);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);
        $deed->forceFill(['notified_at' => now()->subDays($daysAgo)])->save();
        Mail::fake();

        return $deed->fresh();
    }

    private function remind(array $opts = []): string
    {
        Artisan::call('titledeeds:send-reminders', $opts);

        return Artisan::output();
    }

    public function test_a_deed_notified_longer_ago_than_the_reminder_days_gets_one_reminder_with_the_same_pin(): void
    {
        $deed = $this->notifiedDeed(15);
        $pin = app(\App\Services\TitleDeeds\TitleDeedPinService::class)->currentCode($deed);

        $out = $this->remind();

        Mail::assertSent(TitleDeedNoticeMail::class, 1);
        $mail = Mail::sent(TitleDeedNoticeMail::class)->first();
        $this->assertTrue($mail->isReminder);
        $this->assertSame($pin, $mail->pin);
        $deed->refresh();
        $this->assertSame(1, $deed->reminder_count);
        $this->assertNotNull($deed->last_reminder_at);
        $this->assertSame(1, TitleDeedEvent::where('kind', 'reminder')->where('status', 'sent')->count());
        $this->assertSame(0, TitleDeedEvent::where('kind', 'reminder')->where('channel', 'whatsapp')->count(), 'reminders are email only');
        $this->assertTrue(TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'reminder_sent')->exists());
        $this->assertStringContainsString('1 reminder sent', $out);
    }

    public function test_running_it_twice_sends_one_reminder_idempotent(): void
    {
        $deed = $this->notifiedDeed(15);
        $this->remind();
        $this->remind();
        Mail::assertSent(TitleDeedNoticeMail::class, 1);
        $this->assertSame(1, $deed->fresh()->reminder_count);
    }

    public function test_a_deed_notified_recently_is_not_due(): void
    {
        $deed = $this->notifiedDeed(5);
        $this->remind();
        Mail::assertNothingSent();
        $this->assertSame(0, $deed->fresh()->reminder_count);
    }

    public function test_reminders_repeat_every_n_days_and_stop_at_the_cap(): void
    {
        $deed = $this->notifiedDeed(15);
        $this->remind();                                                   // reminder 1
        $this->travel(13)->days();
        $this->remind();                                                   // 13 days later: not due
        $this->assertSame(1, $deed->fresh()->reminder_count);
        $this->travel(1)->days();
        $this->remind();                                                   // 14 days later: reminder 2
        $this->assertSame(2, $deed->fresh()->reminder_count);
        $this->travel(14)->days();
        $this->remind();                                                   // reminder 3
        $this->travel(14)->days();
        $this->remind();                                                   // the cap (3): no 4th
        $this->assertSame(3, $deed->fresh()->reminder_count);
        Mail::assertSent(TitleDeedNoticeMail::class, 3);
    }

    public function test_collected_archived_and_unnotified_deeds_get_nothing(): void
    {
        $collected = $this->notifiedDeed(30);
        $collected->forceFill(['status' => 'collected', 'collected_at' => now()])->save();
        $archived = $this->notifiedDeed(30);
        $archived->delete();
        $fresh = $this->deed([$this->contact('Sipho', 'Mkhize')]);          // received, never notified
        $fresh->forceFill(['received_at' => now()->subDays(60)])->save();

        $this->remind();
        Mail::assertNothingSent();
    }

    public function test_the_agencys_own_settings_decide_days_and_cap(): void
    {
        $this->settings(['reminder_days' => 3, 'max_reminders' => 1]);
        $deed = $this->notifiedDeed(4);
        $this->remind();
        $this->travel(4)->days();
        $this->remind();
        Mail::assertSent(TitleDeedNoticeMail::class, 1);                    // cap of 1
        $this->assertSame(1, $deed->fresh()->reminder_count);

        TitleDeedSetting::where('agency_id', $this->agencyId)->update(['max_reminders' => 0]);
        $d2 = $this->notifiedDeed(10);
        $this->remind();
        $this->assertSame(0, $d2->fresh()->reminder_count, 'a cap of 0 switches reminders off');
    }

    public function test_the_dry_run_lists_without_sending_or_counting(): void
    {
        $deed = $this->notifiedDeed(15);
        $out = $this->remind(['--dry-run' => true]);
        Mail::assertNothingSent();
        $this->assertSame(0, $deed->fresh()->reminder_count);
        $this->assertStringContainsString('[dry-run]', $out);
    }

    public function test_the_agency_filter_only_touches_that_agency(): void
    {
        $deed = $this->notifiedDeed(15);
        $this->remind(['--agency' => $this->agencyId + 999]);
        Mail::assertNothingSent();
        $this->remind(['--agency' => $this->agencyId]);
        Mail::assertSent(TitleDeedNoticeMail::class, 1);
    }

    /* ── settings ── */

    public function test_the_defaults_are_the_approved_ones_and_nothing_is_stored_by_reading(): void
    {
        $a = $this->agencyId;
        // Approved: PIN required, signature required, representatives allowed, consent by phone off, authority letter not required
        // (offered where it applies), photo of ID off, reminders every 14 days up to 3.
        $this->assertSame('required', TitleDeedSetting::pinModeFor($a));
        $this->assertSame('required', TitleDeedSetting::signatureModeFor($a));
        $this->assertTrue(TitleDeedSetting::representativesAllowedFor($a));
        $this->assertSame('owner_consent', TitleDeedSetting::repWithoutPinModeFor($a));
        $this->assertFalse(TitleDeedSetting::phoneConsentAllowedFor($a));
        $this->assertSame('optional', TitleDeedSetting::authorityLetterModeFor($a));
        $this->assertSame('not_used', TitleDeedSetting::collectorIdPhotoModeFor($a));
        $this->assertTrue(TitleDeedSetting::signOnScreenEnabledFor($a));
        $this->assertTrue(TitleDeedSetting::signByLinkEnabledFor($a));
        $this->assertTrue(TitleDeedSetting::notifyListingAgentFor($a));
        $this->assertSame(5, TitleDeedSetting::pinLockAttemptsFor($a));
        $this->assertSame(60, TitleDeedSetting::consentValidityMinutesFor($a));
        $this->assertSame(14, TitleDeedSetting::reminderDaysFor($a));
        $this->assertSame(3, TitleDeedSetting::maxRemindersFor($a));
        $this->assertSame(7, TitleDeedSetting::contactChangedBlockDaysFor($a));
        $this->assertSame(30, TitleDeedSetting::signingLinkMinutesFor($a));
        $this->assertSame(0, TitleDeedSetting::withoutGlobalScopes()->count());
    }

    public function test_the_settings_screen_saves_and_each_agency_has_its_own(): void
    {
        $this->actingAs($this->admin)->get(route('corex.settings.title-deeds.edit'))->assertOk()->assertSee('Title Deed settings');

        $this->actingAs($this->admin)->post(route('corex.settings.title-deeds.update'), [
            'pin_mode' => 'optional', 'signature_mode' => 'required', 'collector_id_photo_mode' => 'optional', 'representatives_allowed' => '1',
            'rep_without_pin_mode' => 'consent_or_letter', 'phone_consent_allowed' => '1', 'authority_letter_mode' => 'optional',
            'sign_on_screen_enabled' => '1', 'sign_by_link_enabled' => '0', 'notify_listing_agent' => '1',
            'pin_lock_attempts' => '8', 'consent_validity_minutes' => '30', 'reminder_days' => '10', 'max_reminders' => '2', 'contact_changed_block_days' => '0', 'signing_link_minutes' => '30',
        ])->assertRedirect(route('corex.settings.title-deeds.edit'));

        $a = $this->agencyId;
        $this->assertSame('optional', TitleDeedSetting::pinModeFor($a));
        $this->assertSame('consent_or_letter', TitleDeedSetting::repWithoutPinModeFor($a));
        $this->assertTrue(TitleDeedSetting::phoneConsentAllowedFor($a));
        $this->assertSame('optional', TitleDeedSetting::collectorIdPhotoModeFor($a));
        $this->assertFalse(TitleDeedSetting::signByLinkEnabledFor($a));
        $this->assertSame(8, TitleDeedSetting::pinLockAttemptsFor($a));
        $this->assertSame(0, TitleDeedSetting::contactChangedBlockDaysFor($a));

        $other = $this->makeAgency('Cape Town');
        $this->assertSame('required', TitleDeedSetting::pinModeFor($other), 'another agency keeps the defaults');
        $this->assertSame(5, TitleDeedSetting::pinLockAttemptsFor($other));
    }

    public function test_bad_values_are_refused_with_a_plain_message_and_nothing_is_saved(): void
    {
        foreach ([['pin_lock_attempts' => '0'], ['pin_lock_attempts' => 'many'], ['consent_validity_minutes' => '2'], ['reminder_days' => '9999'], ['max_reminders' => '-1'], ['rep_without_pin_mode' => 'whatever'], ['pin_mode' => 'maybe'], ['representatives_allowed' => 'perhaps']] as $bad) {
            $this->actingAs($this->admin)->post(route('corex.settings.title-deeds.update'), $bad)->assertSessionHasErrors(array_key_first($bad));
        }
        $this->assertSame(0, TitleDeedSetting::withoutGlobalScopes()->count());
    }

    public function test_a_post_that_never_rendered_a_field_leaves_it_alone_the_wizard_subset_rule(): void
    {
        $this->settings(['phone_consent_allowed' => true, 'pin_lock_attempts' => 9, 'collector_id_photo_mode' => 'required']);

        $this->actingAs($this->admin)->post(route('corex.settings.title-deeds.update'), ['reminder_days' => '21']);

        $a = $this->agencyId;
        $this->assertSame(21, TitleDeedSetting::reminderDaysFor($a));
        $this->assertTrue(TitleDeedSetting::phoneConsentAllowedFor($a), 'an absent answer is not coerced to No');
        $this->assertSame('required', TitleDeedSetting::collectorIdPhotoModeFor($a));
        $this->assertSame(9, TitleDeedSetting::pinLockAttemptsFor($a));
    }

    public function test_the_settings_need_their_own_permission(): void
    {
        $clerk = $this->userWith(['access_title_deeds', 'title_deeds.view']);
        $this->actingAs($clerk)->get(route('corex.settings.title-deeds.edit'))->assertForbidden();
        $this->actingAs($clerk)->post(route('corex.settings.title-deeds.update'), ['reminder_days' => '5'])->assertForbidden();
        $this->assertSame(0, TitleDeedSetting::withoutGlobalScopes()->count());
    }

    public function test_every_setting_is_a_control_in_the_setup_wizard_with_an_explanation_and_a_resolver(): void
    {
        $controls = collect(config('agency-onboarding-copy.properties.controls'))->where('source', 'title_deeds')->keyBy('key');

        $this->assertEqualsCanonicalizing(array_keys(TitleDeedSetting::FIELDS), $controls->keys()->all(), 'the wizard and the settings screen never drift');
        foreach ($controls as $key => $control) {
            $this->assertNotEmpty($control['explain'], "$key explain");
            $this->assertNotEmpty($control['affects'], "$key affects");
            $resolver = \Illuminate\Support\Str::camel($key) . 'For';
            $this->assertTrue(method_exists(TitleDeedSetting::class, $resolver), "$resolver exists");
            $this->assertSame(TitleDeedSetting::{$resolver}($this->agencyId), TitleDeedSetting::{$resolver}($this->agencyId));
        }
        $savers = collect(config('agency-onboarding-copy.properties.savers'))->pluck('method', 'controller');
        $this->assertSame('save', $savers[\App\Http\Controllers\TitleDeeds\TitleDeedSettingsController::class]);
    }

    public function test_the_wizard_step_saves_through_the_same_saver_and_only_what_it_posts(): void
    {
        $this->settings(['pin_lock_attempts' => 9]);
        $req = \Illuminate\Http\Request::create('/x', 'POST', ['reminder_days' => '30', 'phone_consent_allowed' => '1']);
        $req->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\TitleDeeds\TitleDeedSettingsController::class)->save($req);

        $this->assertSame(30, TitleDeedSetting::reminderDaysFor($this->agencyId));
        $this->assertTrue(TitleDeedSetting::phoneConsentAllowedFor($this->agencyId));
        $this->assertSame(9, TitleDeedSetting::pinLockAttemptsFor($this->agencyId));
    }
}
