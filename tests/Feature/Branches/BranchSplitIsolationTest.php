<?php

declare(strict_types=1);

namespace Tests\Feature\Branches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Property;
use App\Models\Scopes\BranchScope;
use App\Models\Scopes\DealBranchScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Split Branches — isolation enforcement.
 *
 * Audit 2026-07-11 (.ai/audits/branch-split-verification-2026-07-11.md) found that
 * BranchScope was attached to the models that existed when it was built, and every
 * model added since had been born unscoped — 15 of them, including the deal
 * pipeline, commission and signed contracts. Nothing failed, because
 * `split_branches_enabled` was never set to true in a single test in the repo.
 *
 * The structural test below is the decay-stopper: any model whose table carries a
 * `branch_id` must either be branch-scoped or be explicitly classified. A new model
 * that forgets the trait fails the suite instead of silently leaking.
 */
final class BranchSplitIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models on a `branch_id` table that are agency-wide ON PURPOSE.
     *
     * Spec §7 shared-scope allowlist — configuration and directory data that every
     * branch is meant to see. Adding a model here is a DESIGN DECISION, not a way
     * to silence the test.
     */
    private const SHARED_BY_DESIGN = [
        \App\Models\BranchSetting::class,                 // a branch's own config — keyed BY branch
        \App\Models\ActivityDefinition::class,            // agency activity catalogue (branch_id = per-branch override)
        \App\Models\CommandCenter\AutomationRule::class,  // agency automation rules
        \App\Models\DealV2\DealPipelineTemplate::class,   // pipeline templates are agency-wide
        // 2026-07-19 (Johan's ruling): both agency-wide, not branch data.
        \App\Models\Compliance\WhistleblowComplaint::class,      // confidential — officer-scoped, agency-wide by design
        \App\Models\Compliance\AgencyComplianceProvision::class, // agency-level compliance config
    ];

    /**
     * KNOWN GAPS — leaking today, awaiting Johan's call on whether they are branch
     * data at all (audit §4). This list must only ever SHRINK. It is not a parking
     * bay: every entry is a model a Margate agent can currently read from Port
     * Shepstone when Split is ON.
     *
     * CommissionLedger is the delicate one: it cannot simply take BelongsToBranch,
     * because the trait auto-stamps the ACTING user's branch, and commission rows
     * are written from queue/console context with no authenticated user — which
     * would leave branch_id NULL and hide agents' own commission from them. It
     * needs the EARNING agent's branch, set explicitly at write time.
     */
    private const PENDING_DECISION = [
        // 2026-07-19: CommissionLedger + the Target/DailyActivity families were scoped
        // this pass (BelongsToBranch + InheritsBranchFromParent(User)); Whistleblow +
        // AgencyComplianceProvision moved to SHARED_BY_DESIGN. What remains has a real
        // open write-context / classification question and stays a tracked gap:
        \App\Models\AgentApplication::class,   // public-portal write (no auth) — branch not resolvable at create yet
        \App\Models\ToolHistoryEntry::class,   // per-user tool history — branch vs shared undecided
        \App\Models\TvAccessCode::class,       // TV display code — no agency_id column; structural question
        \App\Models\TvMessage::class,          // TV display message — no agency_id column
        \App\Models\ListingImportRun::class,   // import operation — agency-wide vs importer-branch undecided
        \App\Models\ListingSnapshot::class,    // child of an import run — follows the run's decision
    ];

    /**
     * THE DECAY-STOPPER.
     *
     * Every model whose table has `branch_id` must be branch-scoped, or listed as
     * shared-by-design, or listed as a known gap. A newly added model that forgets
     * `BelongsToBranch` lands in none of the three and fails here.
     */
    /**
     * Without this, the behavioural isolation tests are a no-op: with an unseeded test DB the
     * default posture (allowAllWhenUnseeded → runningUnitTests() === true) treats EVERY agent as
     * holding `branches.view_all`, so BranchScope is always bypassed and "Margate must not see
     * Port Shepstone" can never be proven. Force the production posture so a plain agent genuinely
     * lacks view_all and BranchScope actually isolates.
     */
    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\PermissionService::forceProductionPosture();
    }

    public function test_every_branch_id_model_is_scoped_or_explicitly_classified(): void
    {
        $unclassified = [];

        foreach ($this->modelsOnBranchIdTables() as $class => $table) {
            if ($this->isBranchScoped($class)) {
                continue;
            }
            if (in_array($class, self::SHARED_BY_DESIGN, true)) {
                continue;
            }
            if (in_array($class, self::PENDING_DECISION, true)) {
                continue;
            }
            $unclassified[] = "{$class} (table: {$table})";
        }

        $this->assertSame(
            [],
            $unclassified,
            "These models sit on a table with a branch_id column but are NOT branch-scoped, and are not "
            . "declared shared-by-design or a known gap.\n\nUnder Split Branches this means one branch's "
            . "agents can read another branch's rows. Either add `use BelongsToBranch` to the model, or — "
            . "if it is deliberately agency-wide — add it to SHARED_BY_DESIGN in this test with a reason.\n\n"
            . "Offenders:\n  - " . implode("\n  - ", $unclassified) . "\n"
        );
    }

    /** The known-gap list is a debt register, not a parking bay. Keep it visible. */
    public function test_known_gap_list_is_not_growing(): void
    {
        $this->assertLessThanOrEqual(
            6,
            count(self::PENDING_DECISION),
            'The branch-isolation known-gap list grew. It must only ever shrink — see '
            . '.ai/audits/branch-split-verification-2026-07-11.md §4 and the 2026-07-19 pass '
            . 'that took it from 14 to 6.'
        );
    }

    /**
     * Behavioural proof, not just structure: with Split ON, a plain agent in one
     * branch must not be able to read another branch's property.
     */
    public function test_split_on_hides_another_branchs_property_from_a_plain_agent(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();

        $agentMargate   = $this->agent($agency, $margate);
        $agentShepstone = $this->agent($agency, $shepstone);

        $theirs = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title'       => '9 Forest Walk, Southbroom',
            'address'     => '9 Forest Walk, Southbroom',
            'agent_id'    => $agentShepstone->id,
            'branch_id'   => $shepstone->id,
            'agency_id'   => $agency->id,
        ]));

        BranchScope::flushCache();

        // The Shepstone agent sees their own stock.
        $this->actingAs($agentShepstone);
        $this->assertTrue(
            Property::whereKey($theirs->id)->exists(),
            'an agent must see their own branch\'s property'
        );

        // The Margate agent must not.
        $this->actingAs($agentMargate);
        $this->assertFalse(
            Property::whereKey($theirs->id)->exists(),
            'SPLIT LEAK: a Margate agent can read a Port Shepstone property'
        );
    }

    /** With Split OFF, the same agent sees everything — the scope must be inert. */
    public function test_split_off_leaves_everything_visible(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();
        $agency->update(['split_branches_enabled' => false]);
        BranchScope::flushCache();

        $agentMargate   = $this->agent($agency, $margate);
        $agentShepstone = $this->agent($agency, $shepstone);

        $theirs = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title'       => '9 Forest Walk, Southbroom',
            'address'     => '9 Forest Walk, Southbroom',
            'agent_id'    => $agentShepstone->id,
            'branch_id'   => $shepstone->id,
            'agency_id'   => $agency->id,
        ]));

        $this->actingAs($agentMargate);
        $this->assertTrue(
            Property::whereKey($theirs->id)->exists(),
            'with Split OFF the branch scope must be completely inert'
        );
    }

    /**
     * A CHILD record's branch is its PARENT's branch — never the acting user's.
     *
     * BelongsToBranch stamps branch_id from whoever is logged in. For a child that
     * is wrong: a principal in Margate (who holds branches.view_all and so may
     * legitimately edit a Port Shepstone deal) would stamp the money line "Margate",
     * and the Shepstone agents whose deal it is would then be unable to see their
     * own money line. InheritsBranchFromParent fixes that.
     */
    public function test_a_child_record_inherits_its_parents_branch_not_the_acting_users(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();

        $principal = User::factory()->create([   // sits in MARGATE
            'agency_id' => $agency->id, 'branch_id' => $margate->id,
            'role' => 'admin', 'is_active' => true,
        ]);
        $agentShepstone = $this->agent($agency, $shepstone);

        // A deal that belongs to PORT SHEPSTONE.
        $dealId = DB::table('deals')->insertGetId($this->requiredColumns('deals') + [
            'agency_id'  => $agency->id,
            'branch_id'  => $shepstone->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BranchScope::flushCache();
        $this->actingAs($principal);   // acting user is in MARGATE

        $line = new \App\Models\DealMoneyLine();
        foreach ($this->requiredColumns('deal_money_lines') as $col => $val) {
            $line->{$col} = $val;
        }
        $line->deal_id   = $dealId;
        $line->agency_id = $agency->id;
        $line->branch_id = null;        // left blank, as a form would leave it
        $line->save();

        $stamped = (int) \App\Models\DealMoneyLine::withoutGlobalScopes()
            ->whereKey($line->id)->value('branch_id');

        $this->assertSame(
            (int) $shepstone->id,
            $stamped,
            'the money line must take the DEAL\'s branch (Port Shepstone), not the acting principal\'s (Margate)'
        );

        // And the agent whose deal it is can actually see it.
        BranchScope::flushCache();
        $this->actingAs($agentShepstone);
        $this->assertTrue(
            \App\Models\DealMoneyLine::whereKey($line->id)->exists(),
            'the Shepstone agent must be able to see the money line on their own deal'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Every NOT NULL column with no default, filled with a type-appropriate value —
     * so a raw insert survives the schema without this test having to know it.
     *
     * @return array<string, mixed>
     */
    private function requiredColumns(string $table): array
    {
        $row = [];
        foreach (DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) as $c) {
            if ($c->EXTRA === 'auto_increment' || $c->IS_NULLABLE === 'YES' || $c->COLUMN_DEFAULT !== null) {
                continue;
            }
            $type = strtolower($c->DATA_TYPE);
            $row[$c->COLUMN_NAME] = match (true) {
                str_contains($type, 'int')     => 1,
                str_contains($type, 'decimal'),
                str_contains($type, 'double'),
                str_contains($type, 'float')   => 0,
                str_contains($type, 'date'),
                str_contains($type, 'time')    => now(),
                str_contains($type, 'json')    => '[]',
                $type === 'enum'               => trim(explode(',', substr($c->COLUMN_TYPE, 5, -1))[0], "'"),
                default                        => 'zz',
            };
        }
        return $row;
    }

    /** @return array{0: Agency, 1: Branch, 2: Branch} */
    private function agencyWithTwoBranches(): array
    {
        $agency = Agency::create([
            'name'                   => 'Coastal ' . Str::random(5),
            'slug'                   => 'coastal-' . Str::random(8),
            'split_branches_enabled' => true,
        ]);

        return [
            $agency,
            Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']),
            Branch::create(['agency_id' => $agency->id, 'name' => 'Port Shepstone']),
        ];
    }

    private function agent(Agency $agency, Branch $branch): User
    {
        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
            'is_active' => true,
        ]);
    }

    private function isBranchScoped(string $class): bool
    {
        if (in_array(BelongsToBranch::class, class_uses_recursive($class), true)) {
            return true;
        }
        // Deal registers DealBranchScope directly (pivot-based, cross-branch register).
        foreach ((new $class)->getGlobalScopes() as $scope) {
            if ($scope instanceof BranchScope || $scope instanceof DealBranchScope) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every Eloquent model in app/Models whose table carries a `branch_id` column.
     *
     * @return array<class-string, string> class => table
     */
    private function modelsOnBranchIdTables(): array
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
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            $found[$class] = $table;
        }

        return $found;
    }
}
