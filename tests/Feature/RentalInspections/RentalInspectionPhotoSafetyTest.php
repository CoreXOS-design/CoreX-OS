<?php

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AT-436 + AT-437 (re-verified 2026-10-07) — the two "silent" inspection-screen defects, pinned as CLASSES.
 *
 *  AT-436: a photo must never be silently lost on any recording surface.
 *   - server half: a photo added before any condition exists survives a reload, and the item still reads unrecorded
 *     (the invariant Johan set: a holding row must never tick the item off).
 *   - client half: the shared uploader (public/js/corex-photo-batch-uploader.js) is exercised for real by
 *     tests/js/photo-batch-uploader.mjs — this file runs it, so a regression fails in the lane-test queue too.
 *   - surfaces: the link-signing pages take no photo uploads at all (read + sign only), so there is nothing there to lose.
 *
 *  AT-437: a control that is shown must work. The cause class — a boolean-attribute binding (:disabled/:checked/…)
 *  reading a lazily-populated object by raw bracket lookup — shipped dead three times. The lint below is the missing
 *  guard: any such raw lookup on an inspection screen fails the build.
 */
class RentalInspectionPhotoSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RI Photo Safety Agency', 'slug' => 'ri-photo-safety-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'RI Photo Safety Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeItem(string $label = 'Bedroom 1'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    // ── AT-436 · server half ─────────────────────────────────────────

    public function test_a_photo_added_before_any_condition_is_still_there_after_a_reload_and_the_item_stays_unrecorded(): void
    {
        Storage::fake('public');
        $unrecorded = $this->makeItem('Bedroom 1');
        $recorded = $this->makeItem('Bedroom 2');
        $inspection = $this->makeInspection();

        // A real condition on the second item, so "recorded" is not simply 0 everywhere.
        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $recorded->id,
            'condition' => RentalInspectionObservation::CONDITION_GOOD,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertOk();

        // Two photos on the item that has NO condition — then the agent closes the tab. Nothing else is touched.
        foreach (['damp-1.jpg', 'damp-2.jpg'] as $name) {
            $this->postJson(route('corex.rental-inspections.photos.store', $inspection), [
                'rental_inspection_item_id' => $unrecorded->id,
                'photos' => [UploadedFile::fake()->image($name)],
            ])->assertStatus(201);
        }

        // "Reload": a brand new read of exactly what the page is built from.
        $payload = RentalInspection::tabPayloadFor($this->property->fresh());
        $tail = $payload['chain_tail'];
        $this->assertNotNull($tail);

        $obs = $tail->observations->where('rental_inspection_item_id', $unrecorded->id);
        $this->assertCount(1, $obs, 'both photos hang off ONE holding observation');
        $this->assertSame(2, $obs->first()->photos->count(), 'both photos are in the payload the page reloads from');
        $this->assertSame(2, $tail->photos->count(), 'and in the whole-inspection photo pool');
        foreach ($obs->first()->photos as $photo) {
            $this->assertNotEmpty($photo->storage_path);
        }

        // The invariant: the holding row must NOT count as recorded anywhere a progress figure comes from.
        $this->assertSame(RentalInspectionObservation::CONDITION_PENDING, $obs->first()->condition);
        $recordedItemIds = $tail->observations
            ->filter(fn ($o) => $o->condition !== RentalInspectionObservation::CONDITION_PENDING)
            ->pluck('rental_inspection_item_id')->unique()->values()->all();
        $this->assertSame([$recorded->id], $recordedItemIds, 'only the item with a real condition reads as recorded (1 of 2, not 2 of 2)');
        $this->assertNull($unrecorded->fresh()->currentObservation());
    }

    public function test_a_failed_upload_leaves_no_half_state_so_a_retry_is_clean(): void
    {
        Storage::fake('public');
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        // A refused upload (wrong file type) is a loud 422 — not a 2xx with nothing saved.
        $this->postJson(route('corex.rental-inspections.photos.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'photos' => [UploadedFile::fake()->create('not-a-photo.exe', 10)],
        ])->assertStatus(422);
        $this->assertSame(0, RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->count());

        // The retry (what the Retry button re-sends) then lands exactly once.
        $this->postJson(route('corex.rental-inspections.photos.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'photos' => [UploadedFile::fake()->image('ok.jpg')],
        ])->assertStatus(201);
        $this->assertSame(1, RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->count());
    }

    // ── AT-436 · surfaces ────────────────────────────────────────────

    public function test_the_link_signing_and_public_report_pages_accept_no_photo_uploads(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'rental-inspection-sign') && ! str_starts_with($uri, 'rental-inspection-report')) {
                continue;
            }
            $checked++;
            $this->assertDoesNotMatchRegularExpression(
                '/photo|upload|image/i',
                $uri . ' ' . ($route->getName() ?? ''),
                "A public/link route ({$uri}) now touches photos — it must carry the same upload-immediately + retry + leave-warning guarantees as the recording screen."
            );
            if (in_array('POST', $route->methods(), true)) {
                $this->assertSame('rental-inspections.sign.submit', $route->getName(), "Unexpected public POST {$uri} — review it for photo handling.");
            }
        }
        $this->assertGreaterThan(0, $checked, 'the public inspection routes were not found — this guard would pass vacuously');
    }

    // ── AT-436 · client half (runs the real uploader in node) ────────

    public function test_the_shared_photo_uploader_never_drops_a_photo_silently(): void
    {
        $node = trim((string) @shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed here — run `node tests/js/photo-batch-uploader.mjs` where it is.');
        }

        $process = new Process([$node, base_path('tests/js/photo-batch-uploader.mjs')], base_path(), null, null, 60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), "tests/js/photo-batch-uploader.mjs failed:\n" . $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('all passed', $process->getOutput());
    }

    // ── AT-437 · the dead-control class ──────────────────────────────

    /** Every Blade file that renders an inspection screen. */
    private function inspectionViews(): array
    {
        $files = [base_path('resources/views/corex/properties/show.blade.php')];
        foreach ([
            'resources/views/corex/properties/partials/rental-inspection-*.blade.php',
            'resources/views/corex/rental-inspections/*.blade.php',
            'resources/views/corex/rental-inspections/partials/*.blade.php',
            'resources/views/rental-inspections/public/*.blade.php',
            'resources/views/rental-inspections/public/partials/*.blade.php',
        ] as $glob) {
            $files = array_merge($files, glob(base_path($glob)) ?: []);
        }

        return array_values(array_unique($files));
    }

    /**
     * The lint. A boolean-attribute binding may read an object by bracket ONLY when the lookup is negated (`!x[k]` —
     * `!` always yields a real boolean whether or not the key exists yet). A raw `x[k]` or `x[k] || …` is `undefined`
     * until something populates it, which is exactly how All Good / Mark room N/A / Resolve rendered dead. Use a
     * coercing method (isObsBusy / isMarkGoodBusy …) instead.
     *
     * @return list<string> offending bindings
     */
    private function rawBracketBindings(string $blade): array
    {
        $bad = [];
        // JS `//` comment lines legitimately quote the old broken bindings ("`:disabled="markNaBusy[…]"` read a…").
        $blade = preg_replace('#^[ \t]*//.*$#m', '', $blade);
        preg_match_all('/(?<![\w-])(?:x-bind)?:(?:disabled|readonly|required|checked|hidden|selected)\s*=\s*"([^"]*)"/', $blade, $m);
        foreach ($m[1] as $expr) {
            // remove properly-negated lookups, then anything left that is still a bracket access is raw
            $rest = preg_replace('/!+\s*[\w$.?]+(?:\[[^\]]*\])+/', '', $expr);
            if (preg_match('/[\w$)\]]\s*\[/', (string) $rest)) {
                $bad[] = trim($expr);
            }
        }

        return $bad;
    }

    public function test_no_boolean_attribute_binding_on_an_inspection_screen_reads_a_lazy_object_by_raw_bracket_lookup(): void
    {
        $offenders = [];
        foreach ($this->inspectionViews() as $file) {
            foreach ($this->rawBracketBindings(file_get_contents($file)) as $expr) {
                $offenders[] = basename($file) . ' → ' . $expr;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A boolean-attribute binding reads a lazily-populated object directly. It is `undefined` until populated, which has left controls dead on this screen three times (AT-437). Wrap it in a method that returns !!value (see isObsBusy / isMarkGoodBusy / isStartBusy in properties/show.blade.php).\n  " . implode("\n  ", $offenders)
        );
    }

    public function test_the_lint_itself_catches_the_exact_shapes_that_shipped_dead(): void
    {
        // the three AT-437 originals + the startBusy sibling must be flagged
        foreach ([
            '<button :disabled="markGoodBusy[group.room?.id]">',
            '<button :disabled="markNaBusy[group.room?.id]">',
            '<button :disabled="discBusy[discrepancy.id] || !discField(discrepancy.id).accepted_observation_id">',
            '<button :disabled="startBusy[\'in\']">',
            '<input :checked="picked[row.id]">',
        ] as $dead) {
            $this->assertNotEmpty($this->rawBracketBindings($dead), "lint missed: {$dead}");
        }
        // the safe shapes must NOT be flagged
        foreach ([
            '<button :disabled="isMarkGoodBusy(group.room?.id)">',
            '<button :disabled="markAllGoodBusy">',
            '<button :disabled="!roomPhotoTagItemChoice[group.room.id]">',
            '<button :disabled="itemBusy || !assignTypeChoice[item.id]">',
            '<button :disabled="!wetInkField(k).file || !!wetInkBusy[k]">',
        ] as $safe) {
            $this->assertSame([], $this->rawBracketBindings($safe), "lint wrongly flagged: {$safe}");
        }
    }

    public function test_the_coercing_methods_the_screen_depends_on_exist_and_are_actually_bound(): void
    {
        $show = file_get_contents(base_path('resources/views/corex/properties/show.blade.php'));
        foreach ([
            'isObsBusy(section, itemId) { return !!this.obsBusy[',
            'isMarkGoodBusy(roomId) { return !!this.markGoodBusy[roomId]; }',
            'isMarkNaBusy(roomId) { return !!this.markNaBusy[roomId]; }',
            'isDiscBusy(discrepancyId) { return !!this.discBusy[discrepancyId]; }',
            'isStartBusy(section) { return !!this.startBusy[section]; }',
        ] as $needle) {
            $this->assertStringContainsString($needle, $show, "missing coercing method: {$needle}");
        }

        // and the buttons actually call them (a method nobody binds protects nothing)
        $rec = file_get_contents(base_path('resources/views/corex/properties/partials/rental-inspection-recording.blade.php'));
        foreach (['isMarkGoodBusy(group.room?.id)', 'isMarkNaBusy(group.room?.id)', 'isDiscBusy(discrepancy.id)', 'isStartBusy('] as $call) {
            $this->assertStringContainsString($call, $rec, "the recording screen no longer binds {$call}");
        }
    }
}
