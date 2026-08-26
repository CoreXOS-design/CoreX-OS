<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Agency;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Track C (HD-11) — the sweeper RECORDS a lapse; it is a transition, never a silent expiry.
 *
 * The pen already stops the instant the legal deadline passes (HD-10). This proves the nightly
 * `signatures:expire` sweep then moves a past-deadline live ceremony to a first-class 'lapsed' state
 * (or 're_lapsed' if it had been revived), with an audit row — so the tracker and the evidence
 * timeline can see and attribute it.
 */
final class CeremonyLapseSweeperTest extends TestCase
{
    use RefreshDatabase;

    private SignatureService $service;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $this->agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
        $this->actingAs($this->agent);
        $this->service = app(SignatureService::class);
    }

    private function ceremony(?string $deadline, string $status): SignatureTemplate
    {
        $doc = Document::create(['name' => 'Sole Mandate', 'owner_id' => $this->agent->id]);

        return SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => $status, 'created_by' => $this->agent->id, 'legal_deadline_at' => $deadline,
        ]);
    }

    public function test_a_past_deadline_live_ceremony_is_recorded_as_lapsed(): void
    {
        $t = $this->ceremony(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_SIGNING);

        $count = $this->service->lapseExpiredCeremonies();

        $this->assertSame(1, $count);
        $this->assertSame(SignatureTemplate::STATUS_LAPSED, $t->fresh()->status);
    }

    /** A revived ceremony that lapses again is re_lapsed, not lapsed — the history is distinguishable. */
    public function test_a_revived_ceremony_relapses(): void
    {
        $t = $this->ceremony(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_REVIVED);

        $this->service->lapseExpiredCeremonies();

        $this->assertSame(SignatureTemplate::STATUS_RE_LAPSED, $t->fresh()->status);
    }

    public function test_the_lapse_writes_an_audit_transition(): void
    {
        $t = $this->ceremony(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_SIGNING);

        $this->service->lapseExpiredCeremonies();

        $log = SignatureAuditLog::where('signature_template_id', $t->id)->where('action', 'ceremony_lapsed')->first();
        $this->assertNotNull($log, 'A lapse must be a recorded transition, never silent.');
        $this->assertSame('signing', $log->metadata_json['from_status'] ?? null);
        $this->assertSame('lapsed', $log->metadata_json['to_status'] ?? null);
    }

    public function test_it_leaves_alone_the_things_it_should(): void
    {
        $future    = $this->ceremony(now()->addDays(30)->toDateTimeString(), SignatureTemplate::STATUS_SIGNING);
        $noClock   = $this->ceremony(null, SignatureTemplate::STATUS_SIGNING);
        $completed = $this->ceremony(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_COMPLETED);
        $already   = $this->ceremony(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_LAPSED);

        $count = $this->service->lapseExpiredCeremonies();

        $this->assertSame(0, $count, 'Future / no-clock / terminal / already-lapsed are all left untouched.');
        $this->assertSame(SignatureTemplate::STATUS_SIGNING, $future->fresh()->status);
        $this->assertSame(SignatureTemplate::STATUS_SIGNING, $noClock->fresh()->status);
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $completed->fresh()->status);
        $this->assertSame(SignatureTemplate::STATUS_LAPSED, $already->fresh()->status);
    }

    /** Idempotent — a second sweep does nothing (the first already recorded it). */
    public function test_the_sweep_is_idempotent(): void
    {
        $this->ceremony(now()->subDay()->toDateTimeString(), SignatureTemplate::STATUS_SIGNING);

        $this->assertSame(1, $this->service->lapseExpiredCeremonies());
        $this->assertSame(0, $this->service->lapseExpiredCeremonies());
    }
}
