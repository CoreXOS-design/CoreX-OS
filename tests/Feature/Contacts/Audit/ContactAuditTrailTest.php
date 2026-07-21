<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts\Audit;

use App\Events\Audit\AuditWriteFailed;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactAuditLog;
use App\Models\User;
use App\Services\Audit\ContactAuditService;
use App\Support\Audit\AuditContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * AT-321-C — the contact audit trail logs EVERY change, always attributable, and
 * never breaks a save. Mirror of PropertyAuditTrailTest. Covers: generic field diff
 * (no allow-list), the dedicated agent_assigned event, excluded-column silence,
 * actor + source attribution, the sanctioned quiet-update helper, the
 * save-survives-audit-failure guarantee, and (where the DB privilege allows the
 * trigger to exist) the unbypassable raw-write backstop.
 */
final class ContactAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        AuditContext::reset();

        $this->agency = Agency::create(['name' => 'Audit Agency', 'slug' => 'caudit-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'name'      => 'Audit Actor',
        ]);
    }

    protected function tearDown(): void
    {
        AuditContext::reset();
        parent::tearDown();
    }

    private function makeContact(array $attrs = []): Contact
    {
        $created = Contact::create(array_merge([
            'agency_id'  => $this->agency->id,
            'agent_id'   => $this->user->id,
            'branch_id'  => $this->branch->id,
            'first_name' => 'Thandi',
            'last_name'  => 'Mokoena',
            'phone'      => '0821234567',
            'email'      => 'thandi@example.co.za',
            'notes'      => 'original',
        ], $attrs));

        // Re-fetch a FRESH instance: a real edit is a separate request, so
        // wasRecentlyCreated is false. On the just-created instance it lingers true
        // and the observer (correctly) treats the save as part of creation, skipping
        // the change-audit — which would make these edit tests vacuous.
        return Contact::withoutGlobalScopes()->findOrFail($created->id);
    }

    private function rows(int $contactId, ?string $eventType = null)
    {
        $q = ContactAuditLog::withoutGlobalScopes()->where('contact_id', $contactId);
        if ($eventType) {
            $q->where('event_type', $eventType);
        }
        return $q->orderBy('id')->get();
    }

    /** 0. Creating a contact writes a contact_created row. */
    public function test_creating_a_contact_is_logged(): void
    {
        $this->actingAs($this->user);
        $c = $this->makeContact();

        $this->assertTrue($this->rows($c->id, 'contact_created')->isNotEmpty(),
            'creating a contact must produce a contact_created row');
    }

    /** 1. LOG EVERYTHING — a generic (non-allow-listed) field change is logged. */
    public function test_generic_field_change_is_logged_with_old_and_new(): void
    {
        $this->actingAs($this->user);
        $c = $this->makeContact();

        $c->notes = 'a brand new note';
        $c->save();

        $row = $this->rows($c->id, 'contact_updated')->last();
        $this->assertNotNull($row, 'a generic field change must produce a contact_updated row');
        $this->assertSame('original', $row->old_values['notes'] ?? null);
        $this->assertSame('a brand new note', $row->new_values['notes'] ?? null);
    }

    /** 2. Dedicated rich event fires for an agent reassignment. */
    public function test_dedicated_agent_assigned_event_fires(): void
    {
        $this->actingAs($this->user);
        $c = $this->makeContact();
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);

        $c->agent_id = $other->id;
        $c->save();

        $this->assertTrue($this->rows($c->id, 'agent_assigned')->isNotEmpty(), 'agent change logs agent_assigned');
        // agent_id must NOT also appear in a generic contact_updated row.
        $genericWithAgent = $this->rows($c->id, 'contact_updated')
            ->filter(fn ($r) => array_key_exists('agent_id', $r->new_values ?? []));
        $this->assertCount(0, $genericWithAgent, 'agent_id is dedicated, never double-logged in the generic row');
    }

    /** 3. Excluded noise columns do NOT create a contact_updated row. */
    public function test_excluded_noise_column_is_not_logged(): void
    {
        $this->actingAs($this->user);
        $c = $this->makeContact();

        $c->last_activity_at = now();  // on the exclusion list
        $c->save();

        $withNoise = $this->rows($c->id, 'contact_updated')
            ->filter(fn ($r) => array_key_exists('last_activity_at', $r->new_values ?? []));
        $this->assertCount(0, $withNoise, 'an excluded noise column must never be logged');
    }

    /** 4. ATTRIBUTION — an authenticated user is recorded as the actor. */
    public function test_actor_is_captured_for_authenticated_user(): void
    {
        $this->actingAs($this->user);
        AuditContext::setUser($this->user);
        $c = $this->makeContact();

        $c->notes = 'edited by a user';
        $c->save();

        $row = $this->rows($c->id, 'contact_updated')->last();
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame('user', $row->actor_type);
        $this->assertNotEmpty($row->actor_label);
    }

    /** 5. ATTRIBUTION — a job/import with no user still gets a clear source, never blank. */
    public function test_source_is_captured_when_there_is_no_user(): void
    {
        // no actingAs — simulate a queued import
        AuditContext::setSource('P24 import', 'import');
        $c = $this->makeContact();

        $c->notes = 'changed by the importer';
        $c->save();

        $row = $this->rows($c->id, 'contact_updated')->last();
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
        $c = $this->makeContact();
        $before = $this->rows($c->id, 'contact_updated')->count();

        $c->auditedQuietUpdate(['notes' => 'set via quiet helper'], 'contact_updated', 'Set notes');

        $this->assertSame($before + 1, $this->rows($c->id, 'contact_updated')->count());
        $this->assertSame('set via quiet helper', $c->fresh()->notes);
    }

    /** 7. ROBUSTNESS — a save STILL succeeds if the audit write throws, and the failure is surfaced. */
    public function test_contact_save_succeeds_even_if_audit_throws(): void
    {
        $this->actingAs($this->user);
        $c = $this->makeContact();
        Event::fake([AuditWriteFailed::class]);

        // Force the audit layer to blow up on the generic diff.
        $throwing = \Mockery::mock(ContactAuditService::class);
        $throwing->shouldReceive('logFieldChanges')->andThrow(new \RuntimeException('boom'));
        $throwing->shouldReceive('log')->andThrow(new \RuntimeException('boom'));
        $throwing->shouldReceive('logAgentAssignment')->andThrow(new \RuntimeException('boom'));
        $throwing->shouldReceive('logContactCreated')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(ContactAuditService::class, $throwing);

        $c->notes = 'save must not 500';
        $c->save();  // must NOT throw

        $this->assertSame('save must not 500', $c->fresh()->notes, 'the contact save must persist');
        Event::assertDispatched(AuditWriteFailed::class);
    }

    /** 8. BACKSTOP — where the DB trigger exists, a raw bypass write is still logged & attributed. */
    public function test_raw_write_is_caught_by_the_backstop_trigger(): void
    {
        $hasTrigger = DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.triggers
             WHERE trigger_schema = DATABASE() AND trigger_name = 'corex_contact_audit_after_update'"
        );
        if (!$hasTrigger || (int) $hasTrigger->c === 0) {
            $this->markTestSkipped('audit trigger not present in this DB (needs SUPER / log_bin_trust_function_creators) — proven on QA1 on-site.');
        }

        $this->actingAs($this->user);
        AuditContext::setUser($this->user);
        $c = $this->makeContact();
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);

        // Raw write that bypasses the Eloquent observer entirely.
        DB::table('contacts')->where('id', $c->id)->update(['agent_id' => $other->id, 'updated_at' => now()]);

        $backstop = $this->rows($c->id)->firstWhere('source', 'db-trigger');
        $this->assertNotNull($backstop, 'a raw bypass write must still produce a backstop row');
        $this->assertNotEmpty($backstop->actor_label, 'the backstop row is attributed');
        $this->assertSame($other->id, (int) ($backstop->new_values['agent_id'] ?? 0),
            'the backstop captures the new agent id');
    }
}
