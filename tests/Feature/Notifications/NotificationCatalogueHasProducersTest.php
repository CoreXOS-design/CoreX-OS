<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\CommandCenter\NotificationEventType;
use Database\Seeders\NotificationEventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-235 (R1) — THE CLASS FIX.
 *
 * A notification toggle in the settings UI is a promise: "turn this on and we will
 * tell you about it". The promise is only real if some code can actually fire the key.
 *
 * On 1 Jul 2026 `ScanContactNotifications.php` was deleted (commit 7e8349a5) — the
 * only producer for four `contact.*` keys. One was retired with it; the rest were
 * left in the catalogue, still rendered in the settings UI, and users *deliberately
 * switched them on*. Nobody noticed.
 *
 * Retiring those rows is the instance fix. This is the class fix: the build now fails
 * if a live catalogue toggle has no producer.
 *
 * ── A NOTE ON HOW THIS TEST NEARLY SHIPPED AS THEATRE ────────────────────────────
 * The catalogue had no seeder — its rows were inserted by a one-off migration, so the
 * TEST database has none (the schema snapshot carries the `migrations` table, so that
 * migration never re-runs). The first version of this test therefore iterated an
 * EMPTY collection and passed while proving nothing. It seeds explicitly now, and
 * asserts the catalogue is non-empty before drawing any conclusion from it.
 */
final class NotificationCatalogueHasProducersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Toggles with no producer that are NOT dead code — they are UNBUILT FEATURES.
     *
     * Six catalogue rows were seeded ahead of a watcher that was never written. They
     * have never fired once (verified against the live dispatch log: only
     * contact.fica_missing, property.documents_missing and contact.birthday appear).
     *
     * They are NOT retired here, because deleting a planned feature's switch is
     * Johan's call, not this test's. They are listed so the debt is explicit and so
     * this guard still catches the NEXT one.
     *
     * ⚠️ ESCALATED TO JOHAN (AT-235 R1): either build the watchers, or retire the rows.
     * Today a user can switch these on and will never hear a thing.
     */
    private const UNBUILT_PENDING_DECISION = [
        'property.no_activity',
        'property.compliance_doc_missing',
        'deal.documents_missing',
        'deal.commission_unpaid',
        'deal.milestone_due',
        'leave.cancelled',
    ];

    /** Fired in code but absent from the catalogue → unconfigurable (AT-235 C9, tracked in R5). */
    private const UNCATALOGUED_KNOWN = [
        'contact.testimonial_submitted',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationEventTypeSeeder::class);
    }

    public function test_the_catalogue_is_actually_seeded(): void
    {
        // Guards the guard: without this, an empty catalogue makes every assertion
        // below vacuously true. That is exactly how the first draft of this test
        // passed while the settings page was full of dead switches.
        $this->assertGreaterThan(
            20,
            NotificationEventType::count(),
            'the catalogue must be seeded, or the producer checks below prove nothing'
        );
    }

    public function test_every_live_catalogue_toggle_can_actually_be_fired(): void
    {
        $orphans = [];

        foreach (NotificationEventType::all() as $type) {
            // Adapter rows are produced by the legacy reminder path (ProcessReminders
            // reads adapter_column off user_dashboard_settings), so they legitimately
            // have no event-key literal in source.
            if ($type->is_adapter) {
                continue;
            }

            if (in_array($type->key, self::UNBUILT_PENDING_DECISION, true)) {
                continue; // known debt, escalated — see the constant's docblock
            }

            if (! $this->hasProducerInSource($type->key)) {
                $orphans[] = $type->key;
            }
        }

        $this->assertSame(
            [],
            $orphans,
            "These notification toggles are shown to users, but NO code can fire them:\n  - "
            . implode("\n  - ", $orphans)
            . "\n\nEither restore a producer, or retire the catalogue row (soft-delete) so it stops "
            . "being offered. A switch that does nothing is a lie told to the user — see AT-235 C7, "
            . "where a deleted scanner orphaned its toggles and users deliberately turned them on."
        );
    }

    public function test_no_new_uncatalogued_event_keys_are_fired(): void
    {
        $catalogued = NotificationEventType::withTrashed()->pluck('key')->all();

        $uncatalogued = array_values(array_diff(
            $this->eventKeysFiredInSource(),
            $catalogued,
            self::UNCATALOGUED_KNOWN
        ));

        $this->assertSame(
            [],
            $uncatalogued,
            "These event keys are fired in code but are NOT in the catalogue, so a user cannot see "
            . "or disable them:\n  - " . implode("\n  - ", $uncatalogued)
            . "\n\nAdd a catalogue row and fire via NotificationDispatcher — AT-235 C9."
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function hasProducerInSource(string $key): bool
    {
        return str_contains($this->appSource(), "'{$key}'")
            || str_contains($this->appSource(), "\"{$key}\"");
    }

    private function eventKeysFiredInSource(): array
    {
        preg_match_all(
            "/(?:->fire\(\s*\\\$\w+\s*,\s*|eventKey:\s*)['\"]([a-z_]+\.[a-z_]+)['\"]/i",
            $this->appSource(),
            $m
        );

        return array_values(array_unique($m[1] ?? []));
    }

    private ?string $source = null;

    private function appSource(): string
    {
        if ($this->source !== null) {
            return $this->source;
        }

        $buffer = '';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $buffer .= file_get_contents($file->getPathname());
            }
        }

        return $this->source = $buffer;
    }
}
