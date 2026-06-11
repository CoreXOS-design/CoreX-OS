<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 9c (AT-16) — finding #13: staging-data sanitisation.
 *
 * Scrubs real seller / agent / data-subject PII out of a database that has
 * been copied from production into a staging environment, so devs, demo
 * watchers, and prospective buyers with staging access never see live
 * personal information (POPIA hygiene).
 *
 * Hard guards:
 *   1. Refuses to run when app.env === 'production' (no message could ever
 *      justify scrubbing live data; this is a one-way street).
 *   2. Requires an explicit --confirm flag — running with no flag prints what
 *      WOULD happen and aborts, so an accidental invocation never mutates data.
 *
 * Discipline:
 *   - UPDATE only, never DELETE. No row is removed; PII columns are overwritten
 *     with deterministic placeholders. Soft-deleted rows are scrubbed too — a
 *     trashed contact still holds the same PII on disk.
 *   - Each table is wrapped in its own transaction. A failure on one table
 *     rolls that table back cleanly and is reported; other tables still run.
 *   - Raw DB::table() is used deliberately so global tenant/soft-delete scopes
 *     do NOT filter the sweep — every row in every agency gets scrubbed.
 *
 * Scrub map (column names verified against migrations, not guessed):
 *   contacts                  email → staging-{id}@example.test, phone → +27000000000
 *   users (except super_admin) email → staging-{id}@example.test
 *   fica_submissions           form_data → "SCRUBBED" (valid JSON string)
 *   contact_notes              body → REDACTED
 *   presentation_teaser_leads  email → staging-{id}@example.test, phone → +27000000000
 */
class StagingSanitise extends Command
{
    protected $signature = 'staging:sanitise {--confirm : Actually perform the scrub. Without this flag the command runs in preview mode and changes nothing.}';

    protected $description = 'Scrub real PII (emails, phones, FICA payloads, notes) out of a production-derived staging database. Refuses to run on production.';

    /** Placeholder phone used for every scrubbed phone column. */
    private const PLACEHOLDER_PHONE = '+27000000000';

    public function handle(): int
    {
        // ── Guard 1: never on production ──────────────────────────────────
        if (app()->environment('production')) {
            $this->error('REFUSED: app.env is "production". staging:sanitise will never scrub live data.');
            $this->line('  This command is for staging copies only. Aborting with no changes.');
            return self::FAILURE;
        }

        $confirmed = (bool) $this->option('confirm');

        $this->line('');
        $this->info('staging:sanitise — environment: ' . app()->environment());
        $this->line('Database: ' . DB::connection()->getDatabaseName());
        if (!$confirmed) {
            $this->warn('PREVIEW MODE — no data will be changed. Re-run with --confirm to apply.');
        } else {
            $this->warn('LIVE MODE — PII columns will be overwritten with placeholders.');
        }
        $this->line('');

        $results = [];

        $results[] = $this->scrubContacts($confirmed);
        $results[] = $this->scrubUsers($confirmed);
        $results[] = $this->scrubFicaSubmissions($confirmed);
        $results[] = $this->scrubContactNotes($confirmed);
        $results[] = $this->scrubPresentationTeaserLeads($confirmed);

        $this->line('');
        $this->table(
            ['Table', 'Rows ' . ($confirmed ? 'scrubbed' : 'to scrub'), 'Status'],
            array_map(fn ($r) => [$r['table'], $r['rows'], $r['status']], $results),
        );

        $failed = array_filter($results, fn ($r) => $r['status'] === 'FAILED');
        if ($failed) {
            $this->error(count($failed) . ' table(s) failed — see log. Other tables committed.');
            return self::FAILURE;
        }

        if (!$confirmed) {
            $this->line('');
            $this->warn('Nothing was changed. Add --confirm to perform the scrub.');
        } else {
            $this->line('');
            $this->info('Sanitisation complete.');
        }

        return self::SUCCESS;
    }

    /**
     * Run a per-table scrub inside its own transaction. The callback returns
     * the number of rows affected. On any failure the transaction rolls back
     * and the table is reported FAILED without aborting the whole command.
     *
     * @param  callable():int  $countCb   Counts rows that match (preview + live).
     * @param  callable():int  $scrubCb   Performs the UPDATE, returns affected rows.
     */
    private function runTable(string $table, bool $confirmed, callable $countCb, callable $scrubCb): array
    {
        if (!Schema::hasTable($table)) {
            return ['table' => $table, 'rows' => 0, 'status' => 'SKIPPED (no table)'];
        }

        try {
            if (!$confirmed) {
                return ['table' => $table, 'rows' => $countCb(), 'status' => 'preview'];
            }

            $rows = DB::transaction(fn () => $scrubCb());

            return ['table' => $table, 'rows' => $rows, 'status' => 'OK'];
        } catch (Throwable $e) {
            report($e);
            $this->error("  {$table}: {$e->getMessage()}");
            return ['table' => $table, 'rows' => 0, 'status' => 'FAILED'];
        }
    }

    private function scrubContacts(bool $confirmed): array
    {
        return $this->runTable(
            'contacts',
            $confirmed,
            fn () => DB::table('contacts')->count(),
            function () {
                $emails = DB::table('contacts')
                    ->whereNotNull('email')
                    ->update(['email' => DB::raw("CONCAT('staging-', id, '@example.test')")]);
                DB::table('contacts')
                    ->whereNotNull('phone')
                    ->update(['phone' => self::PLACEHOLDER_PHONE]);
                // Row count keyed on the email sweep (the broader identifier);
                // phone sweep runs over the same population.
                return max($emails, DB::table('contacts')->count());
            },
        );
    }

    private function scrubUsers(bool $confirmed): array
    {
        // Super admins keep their real login email so platform operators can
        // still sign in to the staging copy. Everyone else (agents, BMs,
        // including role = NULL) is scrubbed.
        $target = fn () => DB::table('users')
            ->where(function ($q) {
                $q->whereNull('role')->orWhere('role', '!=', 'super_admin');
            });

        return $this->runTable(
            'users',
            $confirmed,
            fn () => $target()->count(),
            fn () => $target()->update(['email' => DB::raw("CONCAT('staging-', id, '@example.test')")]),
        );
    }

    private function scrubFicaSubmissions(bool $confirmed): array
    {
        return $this->runTable(
            'fica_submissions',
            $confirmed,
            fn () => DB::table('fica_submissions')->count(),
            // form_data is a JSON column — store a valid JSON string literal so
            // the model's array cast still reads back cleanly ("SCRUBBED").
            fn () => DB::table('fica_submissions')->update(['form_data' => json_encode('SCRUBBED')]),
        );
    }

    private function scrubContactNotes(bool $confirmed): array
    {
        return $this->runTable(
            'contact_notes',
            $confirmed,
            fn () => DB::table('contact_notes')->count(),
            fn () => DB::table('contact_notes')->update(['body' => 'REDACTED']),
        );
    }

    private function scrubPresentationTeaserLeads(bool $confirmed): array
    {
        return $this->runTable(
            'presentation_teaser_leads',
            $confirmed,
            fn () => DB::table('presentation_teaser_leads')->count(),
            function () {
                $emails = DB::table('presentation_teaser_leads')
                    ->whereNotNull('email')
                    ->update(['email' => DB::raw("CONCAT('staging-', id, '@example.test')")]);
                DB::table('presentation_teaser_leads')
                    ->whereNotNull('phone')
                    ->update(['phone' => self::PLACEHOLDER_PHONE]);
                return max($emails, DB::table('presentation_teaser_leads')->count());
            },
        );
    }
}
