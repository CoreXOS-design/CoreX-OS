<?php

namespace Tests\Feature\MultiTenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CLASS-KILL GUARD (AT-203). Raw `DB::table('t')->insert([...])` bypasses the
 * BelongsToAgency `creating` hook entirely — so any raw insert into a table whose
 * `agency_id` is NOT NULL must stamp agency_id itself, or it 500s the day it runs
 * (AT-202 seller/buyer links; AT-203 buyer responses, risk scores, snapshots).
 *
 * This test statically sweeps app/ + routes/ for raw inserts into agency-scoped
 * tables and fails the build if the insert array omits agency_id. It is the
 * structural lock so the NEXT unstamped writer cannot reach live.
 *
 * Scope: inline-array raw inserts (`->insert([ ... ])` / `->insertGetId([ ... ])`),
 * which is where every instance of this bug has occurred. Variable-argument
 * inserts (`->insert($rows)`) cannot be verified statically; known-safe builders
 * are allowlisted below and any NEW one fails until reviewed + allowlisted.
 */
class AgencyIdRawInsertGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Variable-arg raw inserts into scoped tables whose row arrays are built
     * elsewhere and verified (by reading the builder) to stamp agency_id.
     * Format: "relative/path.php:table". Add ONLY after confirming the builder
     * stamps agency_id on every row.
     */
    private const VERIFIED_VARIABLE_ARG_INSERTS = [
        // Each row array below was read and confirmed to stamp agency_id.
        'app/Services/CommandCenter/CalendarEventService.php:calendar_event_links',        // $links[] includes agency_id
        'app/Services/CommandCenter/Calendar/CalendarEventCreator.php:calendar_event_links', // $links[] includes agency_id
        'app/Console/Commands/SeedHoldingCostFromProperties.php:holding_cost_data_points',  // $row['agency_id'] (used in the dedup where)
        'app/Services/DealV2/DealPipelineTemplateProvisioner.php:deal_pipeline_step_dependencies', // $depRows[] => $template->agency_id
        'app/Services/DealV2/DealPipelineService.php:deal_step_instance_dependencies',      // $dependencyRows[] => $deal->agency_id
        'app/Http/Controllers/Settings/Prospecting/BuyerMatchTiersController.php:buyer_match_tiers', // $payload['agency_id'] = $agencyId
        'app/Http/Controllers/FeedbackReportController.php:feedback_reports',               // array_merge($data, ['agency_id' => ...])
    ];

    public function test_no_raw_insert_into_agency_scoped_table_omits_agency_id(): void
    {
        $scoped = $this->agencyScopedTables();
        $this->assertNotEmpty($scoped, 'Expected some NOT-NULL agency_id tables in the schema.');

        $violations = [];
        $unverifiedVariableArgs = [];

        foreach ($this->sourceFiles() as $file) {
            $code = file_get_contents($file);
            $rel = $this->relativePath($file);

            foreach ($this->rawInsertCalls($code) as $call) {
                if (!in_array($call['table'], $scoped, true)) {
                    continue; // not an agency-scoped table — irrelevant
                }
                $line = 1 + substr_count(substr($code, 0, $call['offset']), "\n");

                if ($call['argIsArray']) {
                    if (!preg_match('/[\'"]agency_id[\'"]\s*=>/', $call['argBody'])) {
                        $violations[] = "{$rel}:{$line} — raw insert into `{$call['table']}` (NOT NULL agency_id) omits agency_id";
                    }
                } else {
                    $key = "{$rel}:{$call['table']}";
                    if (!in_array($key, self::VERIFIED_VARIABLE_ARG_INSERTS, true)) {
                        $unverifiedVariableArgs[] = "{$rel}:{$line} — variable-arg insert into `{$call['table']}`; verify the row builder stamps agency_id, then allowlist \"{$key}\"";
                    }
                }
            }
        }

        $messages = array_merge($violations, $unverifiedVariableArgs);
        $this->assertSame(
            [],
            $messages,
            "agency_id raw-insert guard failed (" . count($messages) . "):\n" . implode("\n", $messages)
        );
    }

    /** Tables whose agency_id column is NOT NULL — a raw insert without it will 500. */
    private function agencyScopedTables(): array
    {
        // information_schema column names are uppercase on MySQL — alias to a
        // stable key rather than pluck('table_name') (which returns nulls).
        $rows = DB::select(
            "SELECT table_name AS t FROM information_schema.columns
             WHERE table_schema = ? AND column_name = 'agency_id' AND is_nullable = 'NO'",
            [DB::getDatabaseName()]
        );

        return array_map(fn ($r) => strtolower($r->t), $rows);
    }

    /** All PHP source files under app/ and routes/. */
    private function sourceFiles(): array
    {
        $files = [];
        foreach ([app_path(), base_path('routes')] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        return $files;
    }

    /**
     * Find every `DB::table('t')-> ... ->insert(` / `->insertGetId(` and capture
     * the table, the byte offset, and the balanced argument body (to inspect the
     * inline array or detect a variable argument).
     */
    private function rawInsertCalls(string $code): array
    {
        $calls = [];
        // DB::table('t') optionally chained (->where(...) etc.) then a raw writer.
        // updateOrInsert(attrs, values) is included — its values array must carry
        // agency_id just like a plain insert (the balanced-arg capture spans both
        // arrays, so an agency_id key in either satisfies the check).
        $re = '/DB::table\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)((?:\s*->\s*[a-zA-Z_]+\s*\([^;]*?\))*?)\s*->\s*(?:insert|insertGetId|updateOrInsert)\s*\(/';
        if (!preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $calls;
        }
        foreach ($m as $match) {
            $table = strtolower($match[1][0]);
            $parenOffset = $match[0][1] + strlen($match[0][0]) - 1; // position of the '(' of insert(
            $arg = $this->balancedArg($code, $parenOffset);
            $trimmed = ltrim($arg);
            $calls[] = [
                'table' => $table,
                'offset' => $match[0][1],
                'argIsArray' => str_starts_with($trimmed, '['),
                'argBody' => $arg,
            ];
        }
        return $calls;
    }

    /** Extract the substring between a '(' at $openParen and its matching ')'. */
    private function balancedArg(string $code, int $openParen): string
    {
        $depth = 0;
        $len = strlen($code);
        for ($i = $openParen; $i < $len; $i++) {
            $c = $code[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $openParen + 1, $i - $openParen - 1);
                }
            }
        }
        return substr($code, $openParen + 1); // unbalanced — return remainder
    }

    private function relativePath(string $abs): string
    {
        return ltrim(str_replace(base_path(), '', $abs), '/');
    }
}
