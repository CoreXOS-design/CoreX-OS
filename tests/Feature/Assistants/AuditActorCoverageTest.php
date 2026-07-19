<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Concerns\StampsOnBehalfOf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AT-267 §11 — the decay-stopper for the assistant audit trail.
 *
 * Every bespoke audit table that records a STAFF actor must also record who that staff member
 * acted FOR (`on_behalf_of_user_id`), or an assistant's action is indistinguishable from their
 * agent's — the exact FICA/POPIA/PPRA hole the feature exists to close. A new audit model that
 * forgets the column + `StampsOnBehalfOf` trait fails here instead of silently under-recording.
 *
 * Mirrors BranchSplitIsolationTest: three buckets, and adding a model to a list is a decision.
 */
final class AuditActorCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Columns that name the staff member who performed an audited action. */
    private const ACTOR_COLUMNS = ['user_id', 'actor_user_id', 'actor_id', 'performed_by_user_id'];

    /**
     * Audit-shaped tables whose "actor" column is NOT a staff actor — a notification recipient
     * or a system dispatch — so there is no on-behalf-of to record. Declaring one here is a
     * decision that its `user_id` is the subject, not the actor.
     */
    private const NO_STAFF_ACTOR = [
        \App\Models\CommandCenter\CalendarReminderLog::class,   // user_id = who was reminded
        \App\Models\CommandCenter\NotificationDispatchLog::class, // user_id = the recipient
    ];

    /**
     * Genuine staff audits outside AT-267 §11's original 10, awaiting their own coverage pass.
     * A debt register that must only ever SHRINK — never a parking bay.
     */
    private const PENDING_COVERAGE = [
        \App\Models\Proforma\ProformaInvoiceAudit::class,
        \App\Models\Compliance\WhistleblowAuditLog::class,
    ];

    public function test_every_staff_audit_model_records_on_behalf_of(): void
    {
        $offenders = [];

        foreach ($this->staffAuditModels() as $class => $table) {
            if (in_array($class, self::NO_STAFF_ACTOR, true)) {
                continue;
            }
            if (in_array($class, self::PENDING_COVERAGE, true)) {
                continue;
            }

            $hasColumn = Schema::hasColumn($table, 'on_behalf_of_user_id');
            $hasTrait  = in_array(StampsOnBehalfOf::class, class_uses_recursive($class), true);

            if (!$hasColumn || !$hasTrait) {
                $offenders[] = "{$class} ({$table}) — column=" . ($hasColumn ? 'Y' : 'N')
                    . ' trait=' . ($hasTrait ? 'Y' : 'N');
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These audit models record a staff actor but not who they acted FOR.\n"
            . "Add `use StampsOnBehalfOf` + the on_behalf_of_user_id column, or — if the actor is a "
            . "recipient/external party — add the model to NO_STAFF_ACTOR with a reason.\n\n"
            . "Offenders:\n  - " . implode("\n  - ", $offenders) . "\n"
        );
    }

    /** domain_event_log is written raw (no model) — assert the column exists directly. */
    public function test_raw_written_domain_event_log_carries_the_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('domain_event_log', 'on_behalf_of_user_id'),
            'domain_event_log (the central cross-pillar audit) must carry on_behalf_of_user_id.'
        );
    }

    /** The debt register only shrinks. */
    public function test_pending_coverage_is_not_growing(): void
    {
        $this->assertLessThanOrEqual(
            2,
            count(self::PENDING_COVERAGE),
            'The audit-actor coverage debt grew. It must only ever shrink.'
        );
    }

    /**
     * Every Eloquent model in app/Models whose table looks like a staff audit log (name ends in
     * _log/_logs or contains "audit") AND carries one of the actor columns.
     *
     * @return array<class-string, string> class => table
     */
    private function staffAuditModels(): array
    {
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Models')));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace([app_path('Models') . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class    = 'App\\Models\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (!class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            if ($ref->isAbstract() || !$ref->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            $table = (new $class)->getTable();
            if (!Schema::hasTable($table) || !preg_match('/_logs?$|audit/i', $table)) {
                continue;
            }
            foreach (self::ACTOR_COLUMNS as $col) {
                if (Schema::hasColumn($table, $col)) {
                    $found[$class] = $table;
                    break;
                }
            }
        }

        return $found;
    }
}
