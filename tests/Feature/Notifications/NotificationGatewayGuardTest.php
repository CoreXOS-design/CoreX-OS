<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Tests\TestCase;

/**
 * AT-235 (R7) — THE BUILD GUARD.
 *
 * The single-owner model says: nothing sends a user-facing notification except
 * through the gateway (`NotificationDispatcher::fire()`), because that is the only
 * place that checks the user's preference, the agency's policy, the open-hours
 * window, the cooldown, and the idempotency ledger.
 *
 * Today it governs **8 of ~36 send sites**. The other ~80% call `->notify()` /
 * `Notification::send()` directly and are invisible to all of it: a user cannot turn
 * them off, and nothing records that they happened.
 *
 * R5 migrates those to the gateway, in slices. This guard exists so that while that
 * work is in flight, **the debt cannot grow**. Every current bypass is frozen into
 * the allow-list below. Add a new one and the BUILD fails.
 *
 * Without this, the 34th bypass gets added next month, and the one after that, and
 * the migration never finishes — which is exactly how we arrived at 31.
 *
 * ── SCOPE, HONESTLY ─────────────────────────────────────────────────────────────
 * This guards the **Laravel notification layer** (`->notify()`, `Notification::*`),
 * which is precisely what the preference system governs. It deliberately does NOT
 * try to catch raw `Mail::to(...)` sends, because a static test cannot reliably tell
 * a mail to a *User* (which should be governed) from a mail to a *client contact*
 * (which legitimately is not — e.g. a signing request to a seller). Two known
 * user-facing mail bypasses live outside this net and are tracked in R5:
 *   - SendCalendarDigests.php        Mail::to($user->email) — the 06:30 digest
 *   - OversightDigestJob.php         the hourly oversight digest
 * They are named here so the gap is on the record rather than pretended away.
 */
final class NotificationGatewayGuardTest extends TestCase
{
    /** The gateway itself, and the secondary dispatcher R4 will fold into it. */
    private const DISPATCHERS = [
        'app/Services/CommandCenter/NotificationDispatcher.php',
        // Agency per-class routing. It applies the class config + the user's master
        // switches (AT-235 R2) but not the per-event preference or the ledger.
        // R4 merges it into the gateway.
        'app/Services/CommandCenter/Calendar/CalendarNotificationDispatcher.php',
    ];

    /**
     * FROZEN DEBT — every notification bypass that existed when this guard landed.
     *
     * Each of these sends a user-facing notification WITHOUT consulting the user's
     * preference, without an open-hours check, without a cooldown, and without
     * writing to notification_dispatch_log. A user cannot switch any of them off.
     *
     * This list may only ever SHRINK. R5 empties it, module by module.
     */
    private const KNOWN_BYPASSES = [
        // ── Docuperfect / e-sign (Tier C — no switch at all) ──
        'app/Services/Docuperfect/SignatureService.php',
        'app/Http/Controllers/Docuperfect/SalesDocumentController.php',
        'app/Http/Controllers/Docuperfect/SigningController.php',
        'app/Console/Commands/SendSignatureReminders.php',

        // ── Presentations (Tier C — no switch at all) ──
        'app/Services/Presentations/RefreshRequestService.php',
        'app/Services/Presentations/PresentationOutcomeService.php',
        'app/Services/Presentations/PresentationDeliveryService.php',
        'app/Http/Controllers/Presentation/PublicPresentationController.php',
        'app/Jobs/PromptOutcomeCaptureJob.php',
        // (RefreshDeclinedNotification was listed here in error — it is a notification
        // CLASS, not a sender; it only mentions Notification::route() in a docblock.
        // The comment-stripping guard caught my own false entry. The real sender is
        // RefreshRequestService, listed above.)

        // ── Deals ──
        'app/Services/DealV2/NotificationService.php',

        // ── Leads (also ignores the push master switch — AT-235 C10) ──
        'app/Listeners/Leads/EmailPortalLeadToAgent.php',

        // ── Contacts — fires contact.testimonial_submitted, a key that is not even
        //    in the catalogue, so it cannot be configured or suppressed (C9) ──
        'app/Listeners/Contacts/NotifyAgentOfClientTestimonial.php',

        // ── Communications ──
        'app/Services/Communications/MailboxHealthRecorder.php',
        'app/Services/Communications/CommsAccessGrantService.php',

        // ── Reminders / scheduled ──
        'app/Services/CommandCenter/CalendarReminderService.php',
        'app/Console/Commands/CommandCenter/ProcessReminders.php',
        'app/Console/Commands/CheckLeaseExpiry.php',

        // ── Misc ──
        'app/Jobs/MatchPropertyJob.php',
        'app/Jobs/SendAgentInviteJob.php',
        'app/Jobs/RcrDeadlineReminderJob.php',
        'app/Http/Controllers/Admin/ImporterController.php',
    ];

    public function test_no_new_notification_bypasses_are_added(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnderApp() as $relative => $source) {
            if (in_array($relative, self::DISPATCHERS, true)) {
                continue; // the gateway is allowed to send — that is its job
            }

            if (! $this->sendsANotification($source)) {
                continue;
            }

            if (in_array($relative, self::KNOWN_BYPASSES, true)) {
                continue; // frozen debt — R5 removes it from the list by fixing it
            }

            $offenders[] = $relative;
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "NEW notification bypass(es) — these send a user-facing notification without going "
            . "through NotificationDispatcher::fire():\n  - " . implode("\n  - ", $offenders)
            . "\n\nA notification sent this way ignores the user's preference, the open-hours "
            . "window, the cooldown and the idempotency ledger — the user cannot turn it off, and "
            . "nothing records that it happened.\n\n"
            . "Send via NotificationDispatcher::fire() and register the event key in "
            . "notification_event_types (AT-235, the single-owner model). If it genuinely cannot "
            . "go through the gateway yet, that is a decision for Johan — not a line added quietly "
            . "to the allow-list."
        );
    }

    /**
     * The debt must never grow. If a bypass is FIXED, delete it from the allow-list —
     * a stale entry silently re-permits a future regression in that same file.
     */
    public function test_the_allow_list_contains_no_stale_entries(): void
    {
        $stale = [];

        foreach (self::KNOWN_BYPASSES as $relative) {
            $path = base_path($relative);

            if (! file_exists($path)) {
                $stale[] = "{$relative} (file no longer exists)";
                continue;
            }

            if (! $this->sendsANotification(file_get_contents($path))) {
                $stale[] = "{$relative} (no longer sends — remove it from the allow-list)";
            }
        }

        $this->assertSame(
            [],
            $stale,
            "The AT-235 bypass allow-list is stale:\n  - " . implode("\n  - ", $stale)
            . "\n\nRemove fixed files from KNOWN_BYPASSES. A stale entry is a hole: it would "
            . "silently re-permit a bypass being reintroduced into that file later."
        );
    }

    /** A count, so the direction of travel is visible in the test output. */
    public function test_the_bypass_debt_is_recorded(): void
    {
        $this->assertLessThanOrEqual(
            21,
            count(self::KNOWN_BYPASSES),
            'the bypass allow-list may only ever SHRINK — S2 empties it. '
            . '23 -> 22 when AT-245 proforma became citizen #1; -> 21 when a false entry '
            . '(a notification class, not a sender) was removed.'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function sendsANotification(string $source): bool
    {
        // Scan CODE, not prose. A docblock that quotes the old bypass — e.g.
        // ProformaGenerationService explaining what it used to do before it became
        // citizen #1 — must not trip the guard. Strip comments first, or the guard
        // punishes people for documenting the very fix it asked them to make.
        $code = $this->stripComments($source);

        foreach (['->notify(', 'Notification::send(', 'Notification::sendNow(', 'Notification::route('] as $needle) {
            if (str_contains($code, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Source with all comments and docblocks removed. */
    private function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /** @return array<string,string> relative path => source */
    private function phpFilesUnderApp(): array
    {
        $out = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());
            $out[$relative] = file_get_contents($file->getPathname());
        }

        return $out;
    }
}
