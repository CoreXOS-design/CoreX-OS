<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Mail\Signatures\SignatureReminderMail;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Notifications\SignatureTeamAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-2 — sales-side reminders never fired.
 *
 * `SendSignatureReminders` filtered signing sessions to signing / awaiting_tenant /
 * awaiting_landlord. Every SALES signing (awaiting_buyer, awaiting_seller) was therefore
 * skipped: the seller sitting on a mandate was never reminded, and the team alert that
 * exists to catch exactly that never fired. It was silent, and it was live.
 *
 * These tests assert the REMINDER GOES OUT and the COUNTER MOVES — per status — rather
 * than that the command merely runs. The rental statuses are re-asserted so the fix
 * cannot regress the side that always worked.
 */
final class SendSignatureRemindersTest extends TestCase
{
    use RefreshDatabase;

    // ── The production hole: sales was never chased ──

    /** @dataProvider salesStatuses */
    public function test_sales_signings_are_reminded(string $status): void
    {
        Mail::fake();
        $request = $this->seedPendingSigner($status, daysAgo: 2);

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertSent(SignatureReminderMail::class, 1);
        $this->assertSame(1, $request->fresh()->reminder_count, 'reminder_count must move for a sales signing');
    }

    public static function salesStatuses(): array
    {
        return [
            'awaiting_seller (mandate — the reported case)' => [SignatureTemplate::STATUS_AWAITING_SELLER],
            'awaiting_buyer'                                => [SignatureTemplate::STATUS_AWAITING_BUYER],
        ];
    }

    // ── No regression on the side that already worked, + the rest of the enum ──

    /** @dataProvider otherAwaitingStatuses */
    public function test_every_awaiting_signer_status_is_reminded(string $status): void
    {
        Mail::fake();
        $request = $this->seedPendingSigner($status, daysAgo: 2);

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertSent(SignatureReminderMail::class, 1);
        $this->assertSame(1, $request->fresh()->reminder_count);
    }

    public static function otherAwaitingStatuses(): array
    {
        return [
            'signing (unchanged)'          => [SignatureTemplate::STATUS_SIGNING],
            'awaiting_tenant (unchanged)'  => [SignatureTemplate::STATUS_AWAITING_TENANT],
            'awaiting_landlord (unchanged)'=> [SignatureTemplate::STATUS_AWAITING_LANDLORD],
            'awaiting_deferred'            => [SignatureTemplate::STATUS_AWAITING_DEFERRED],
            'partial'                      => [SignatureTemplate::STATUS_PARTIAL],
            // Amendment doctrine (ceremony §5): parties owe an amendment-initial on the
            // new content — they are external signers, so they get chased.
            'amendment_initialing'         => [SignatureTemplate::STATUS_AMENDMENT_INITIALING],
        ];
    }

    /**
     * The other half of "fix the class": we must not start emailing signers while the
     * document is parked on one of OUR people. Chasing a signer for a document sitting
     * in the agent's own approval queue would be nagging them for our delay.
     *
     * @dataProvider internalStatuses
     */
    public function test_signings_waiting_on_an_internal_actor_are_not_reminded(string $status): void
    {
        Mail::fake();
        $request = $this->seedPendingSigner($status, daysAgo: 12);

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, $request->fresh()->reminder_count);
    }

    public static function internalStatuses(): array
    {
        return [
            'pending_agent_approval'   => [SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL],
            'awaiting_supervisor'      => [SignatureTemplate::STATUS_AWAITING_SUPERVISOR],
            'amendment_review'         => [SignatureTemplate::STATUS_AMENDMENT_REVIEW],
            'draft (never sent)'       => [SignatureTemplate::STATUS_DRAFT],
            'completed (terminal)'     => [SignatureTemplate::STATUS_COMPLETED],
            'cancelled (terminal)'     => [SignatureTemplate::STATUS_CANCELLED],
        ];
    }

    // ── The escalation ladder, on a sales document ──

    public function test_ladder_climbs_gentle_then_firm_then_final_on_a_sales_signing(): void
    {
        Mail::fake();

        $gentle = $this->seedPendingSigner(SignatureTemplate::STATUS_AWAITING_SELLER, daysAgo: 2);
        $firm   = $this->seedPendingSigner(SignatureTemplate::STATUS_AWAITING_SELLER, daysAgo: 5, reminderCount: 1);
        $final  = $this->seedPendingSigner(SignatureTemplate::STATUS_AWAITING_BUYER, daysAgo: 10, reminderCount: 2, teamAlertedAt: now());

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertSent(SignatureReminderMail::class, 3);
        $this->assertSame(1, $gentle->fresh()->reminder_count, 'gentle = 1st email');
        $this->assertSame(2, $firm->fresh()->reminder_count, 'firm = 2nd email');
        $this->assertSame(3, $final->fresh()->reminder_count, 'final = 3rd email');
    }

    /** Day 7 with no prior alert = the team alert fires — for a SALES document, which it never did. */
    public function test_team_alert_fires_for_a_sales_signing(): void
    {
        Mail::fake();
        Notification::fake();

        $request = $this->seedPendingSigner(SignatureTemplate::STATUS_AWAITING_SELLER, daysAgo: 7, reminderCount: 2);

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Notification::assertSentTimes(SignatureTeamAlert::class, 1);
        $this->assertNotNull($request->fresh()->team_alerted_at, 'team_alerted_at must be stamped so it fires once');
    }

    // ── config('signatures.reminders.max_email_reminders') — declared, never read ──

    public function test_max_email_reminders_is_honoured_from_config(): void
    {
        Mail::fake();
        config()->set('signatures.reminders.max_email_reminders', 3);

        // Already had its 3 emails, sitting well past the final threshold.
        $capped = $this->seedPendingSigner(
            SignatureTemplate::STATUS_AWAITING_SELLER,
            daysAgo: 20,
            reminderCount: 3,
            teamAlertedAt: now(),
        );

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(3, $capped->fresh()->reminder_count, 'the cap must hold');
    }

    /**
     * The proof the config value is actually READ, not hardcoded: lowering the cap to 1
     * must stop a signer who has already had 1 email — under the old hardcoded ladder
     * (`< 2` / `< 3`) they would have been emailed again regardless of config.
     */
    public function test_lowering_the_cap_in_config_actually_takes_effect(): void
    {
        Mail::fake();
        config()->set('signatures.reminders.max_email_reminders', 1);

        $request = $this->seedPendingSigner(
            SignatureTemplate::STATUS_AWAITING_SELLER,
            daysAgo: 12,
            reminderCount: 1,
            teamAlertedAt: now(),
        );

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(1, $request->fresh()->reminder_count, 'cap of 1 means no second email, ever');
    }

    /** A cap of 0 means the ladder is switched off entirely — not "the first rung fires anyway". */
    public function test_a_cap_of_zero_sends_nothing(): void
    {
        Mail::fake();
        config()->set('signatures.reminders.max_email_reminders', 0);

        $request = $this->seedPendingSigner(SignatureTemplate::STATUS_AWAITING_SELLER, daysAgo: 3);

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, $request->fresh()->reminder_count);
    }

    // ── Guards that must survive the widened net ──

    public function test_expired_request_is_marked_expired_and_not_emailed(): void
    {
        Mail::fake();
        $request = $this->seedPendingSigner(
            SignatureTemplate::STATUS_AWAITING_SELLER,
            daysAgo: 5,
            expiresAt: now()->subDay(),
        );

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(SignatureRequest::STATUS_EXPIRED, $request->fresh()->status);
    }

    /** A signer who posted a wet-ink upload has done their part — chasing them is wrong. */
    public function test_wet_ink_upload_pending_review_is_not_chased(): void
    {
        Mail::fake();
        $request = $this->seedPendingSigner(
            SignatureTemplate::STATUS_AWAITING_SELLER,
            daysAgo: 9,
            wetInkStatus: SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
        );

        $this->artisan('signatures:send-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, $request->fresh()->reminder_count);
    }

    // ── Helper ──

    private function seedPendingSigner(
        string $templateStatus,
        int $daysAgo,
        int $reminderCount = 0,
        ?\Illuminate\Support\Carbon $teamAlertedAt = null,
        ?\Illuminate\Support\Carbon $expiresAt = null,
        ?string $wetInkStatus = null,
    ): SignatureRequest {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Elize van Wyk', 'email' => 'elize-' . Str::random(6) . '@hfcoastal.co.za',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Exclusive Authority To Sell (V10)',
            'render_type' => 'web',
            'template_type' => 'cds',
            'category' => 'sales',
            'signing_parties' => ['owner_party', 'agent'],
            'field_mappings' => [],
            'owner_id' => $userId,
        ]);

        $doc = Document::create([
            'name' => 'EATS — 14 Marine Drive, Shelly Beach',
            'document_type' => 'agreement',
            'owner_id' => $userId,
            'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div>body</div>'],
        ]);

        $sigTmpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => Str::random(64),
            'status' => $templateStatus,
            'created_by' => $userId,
        ]);

        return SignatureRequest::create([
            'signature_template_id' => $sigTmpl->id,
            'party_role'    => 'seller',
            'role_index'    => 1,
            'signer_name'   => 'Thandeka Mkhize',
            'signer_email'  => 'thandeka.mkhize-' . Str::random(4) . '@gmail.com',
            'token'         => Str::random(48),
            'token_expires_at' => $expiresAt ?? now()->addDays(30),
            'status'        => SignatureRequest::STATUS_PENDING,
            'signing_order' => 1,
            'sent_at'       => now()->subDays($daysAgo),
            'reminder_count'=> $reminderCount,
            'team_alerted_at' => $teamAlertedAt,
            'wet_ink_status'  => $wetInkStatus,
        ]);
    }
}
