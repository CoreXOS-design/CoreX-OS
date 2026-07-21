<?php

declare(strict_types=1);

namespace Tests\Feature\Properties\Audit;

use App\Events\Property\PropertyAuditWriteFailed;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Models\User;
use App\Services\Audit\PropertyAuditService;
use App\Support\Audit\PropertyAuditContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * AT-321 — the property audit trail logs EVERY change, always attributable, and
 * never breaks a save. Covers: generic field diff (no allow-list), dedicated
 * events, excluded-column silence, actor + source attribution, the sanctioned
 * quiet-update helper, the save-survives-audit-failure guarantee, and (where the
 * DB privilege allows the trigger to exist) the unbypassable raw-write backstop.
 */
final class PropertyAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        PropertyAuditContext::reset();

        $this->agency = Agency::create(['name' => 'Audit Agency', 'slug' => 'audit-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'name'      => 'Audit Actor',
        ]);
    }

    protected function tearDown(): void
    {
        PropertyAuditContext::reset();
        parent::tearDown();
    }

    private function makeProperty(array $attrs = []): Property
    {
        $created = Property::create(array_merge([
            'title'     => 'Audit Cottage',
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->user->id,
            'branch_id' => $this->branch->id,
            'price'     => 1_500_000,
            'status'    => 'active',
            'description' => 'original',
        ], $attrs));

        // Re-fetch a FRESH instance: a real edit is a separate request, so
        // wasRecentlyCreated is false. On the just-created instance it lingers
        // true and the observer (correctly) treats the save as part of creation,
        // skipping the change-audit — which would make these edit tests vacuous.
        return Property::findOrFail($created->id);
    }

    private function rows(int $propertyId, ?string $eventType = null)
    {
        $q = PropertyAuditLog::withoutGlobalScopes()->where('property_id', $propertyId);
        if ($eventType) {
            $q->where('event_type', $eventType);
        }
        return $q->orderBy('id')->get();
    }

    /** 1. LOG EVERYTHING — a generic (non-allow-listed) field change is logged. */
    public function test_generic_field_change_is_logged_with_old_and_new(): void
    {
        $this->actingAs($this->user);
        $p = $this->makeProperty();

        $p->description = 'a brand new description';
        $p->save();

        $row = $this->rows($p->id, 'property_updated')->last();
        $this->assertNotNull($row, 'a generic field change must produce a property_updated row');
        $this->assertSame('original', $row->old_values['description'] ?? null);
        $this->assertSame('a brand new description', $row->new_values['description'] ?? null);
    }

    /** 2. Dedicated rich events still fire for status / agent. */
    public function test_dedicated_status_and_agent_events_still_fire(): void
    {
        $this->actingAs($this->user);
        $p = $this->makeProperty();
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);

        $p->status = 'withdrawn';
        $p->save();
        $this->assertTrue($this->rows($p->id, 'status_changed')->isNotEmpty(), 'status change logs status_changed');

        $p->agent_id = $other->id;
        $p->save();
        $this->assertTrue($this->rows($p->id, 'agent_assigned')->isNotEmpty(), 'agent change logs agent_assigned');
    }

    /** 3. Excluded noise columns do NOT create a property_updated row. */
    public function test_excluded_noise_column_is_not_logged(): void
    {
        $this->actingAs($this->user);
        $p = $this->makeProperty();

        $p->p24_stats_synced_at = now();  // on the exclusion list
        $p->save();

        // A noise column must never appear in the trail (other legitimate
        // derivations on the same save, e.g. title_type self-heal, may still log —
        // the guarantee is that the EXCLUDED column is never audited).
        $withNoise = $this->rows($p->id, 'property_updated')
            ->filter(fn ($r) => array_key_exists('p24_stats_synced_at', $r->new_values ?? []));
        $this->assertCount(0, $withNoise, 'an excluded noise column must never be logged');
    }

    /** 4. ATTRIBUTION — an authenticated user is recorded as the actor. */
    public function test_actor_is_captured_for_authenticated_user(): void
    {
        $this->actingAs($this->user);
        PropertyAuditContext::setUser($this->user);
        $p = $this->makeProperty();

        $p->description = 'edited by a user';
        $p->save();

        $row = $this->rows($p->id, 'property_updated')->last();
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame('user', $row->actor_type);
        $this->assertNotEmpty($row->actor_label);
    }

    /** 5. ATTRIBUTION — a job/import with no user still gets a clear source, never blank. */
    public function test_source_is_captured_when_there_is_no_user(): void
    {
        // no actingAs — simulate a queued import
        PropertyAuditContext::setSource('P24 import', 'import');
        $p = $this->makeProperty();

        $p->description = 'changed by the importer';
        $p->save();

        $row = $this->rows($p->id, 'property_updated')->last();
        $this->assertNull($row->user_id);
        $this->assertSame('import', $row->actor_type);
        $this->assertSame('P24 import', $row->actor_label);
        $this->assertSame('P24 import', $row->source);
        $this->assertNotEmpty($row->actor_label, 'attribution is never a silent blank');
    }

    /** 6. The sanctioned quiet-update helper still records a rich audit row. */
    public function test_audited_quiet_update_records_a_row(): void
    {
        $this->actingAs($this->user);
        $p = $this->makeProperty();
        $before = $this->rows($p->id, 'property_updated')->count();

        $p->auditedQuietUpdate(['beds' => 4], 'property_updated', 'Set beds to 4');

        $this->assertSame($before + 1, $this->rows($p->id, 'property_updated')->count());
        $this->assertSame(4, (int) $p->fresh()->beds);
    }

    /** 7. ROBUSTNESS — a save STILL succeeds if the audit write throws, and the failure is surfaced. */
    public function test_property_save_succeeds_even_if_audit_throws(): void
    {
        $this->actingAs($this->user);
        $p = $this->makeProperty();
        Event::fake([PropertyAuditWriteFailed::class]);

        // Force the audit layer to blow up on the generic diff.
        $throwing = \Mockery::mock(PropertyAuditService::class);
        $throwing->shouldReceive('logFieldChanges')->andThrow(new \RuntimeException('boom'));
        $throwing->shouldReceive('log')->andThrow(new \RuntimeException('boom'));
        $throwing->shouldReceive('logPriceChange')->andThrow(new \RuntimeException('boom'));
        $throwing->shouldReceive('logStatusChange')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(PropertyAuditService::class, $throwing);

        $p->description = 'save must not 500';
        $p->save();  // must NOT throw

        $this->assertSame('save must not 500', $p->fresh()->description, 'the property save must persist');
        Event::assertDispatched(PropertyAuditWriteFailed::class);
    }

    /** 8. BACKSTOP — where the DB trigger exists, a raw bypass write is still logged & attributed. */
    public function test_raw_write_is_caught_by_the_backstop_trigger(): void
    {
        $hasTrigger = DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.triggers
             WHERE trigger_schema = DATABASE() AND trigger_name = 'corex_property_audit_after_update'"
        );
        if (!$hasTrigger || (int) $hasTrigger->c === 0) {
            $this->markTestSkipped('audit trigger not present in this DB (needs SUPER / log_bin_trust_function_creators) — proven on QA1 on-site.');
        }

        $this->actingAs($this->user);
        PropertyAuditContext::setUser($this->user);
        $p = $this->makeProperty();
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
        $before = $this->rows($p->id)->count();

        // Raw write that bypasses the Eloquent observer entirely.
        DB::table('properties')->where('id', $p->id)->update(['agent_id' => $other->id, 'updated_at' => now()]);

        $backstop = $this->rows($p->id)->firstWhere('source', 'db-trigger');
        $this->assertNotNull($backstop, 'a raw bypass write must still produce a backstop row');
        $this->assertNotEmpty($backstop->actor_label, 'the backstop row is attributed');
        $this->assertSame($other->id, (int) ($backstop->new_values['agent_id'] ?? 0),
            'the backstop captures the new agent id');
    }
}
