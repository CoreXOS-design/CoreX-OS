<?php

namespace Tests\Feature\Diagnostics;

use App\Http\Controllers\CoreX\PhotoUploadDiagnosticsController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\MobilePhotoEvent;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * The report may only subtract a photo the AGENT chose to delete.
 *
 * The app emits `dropped` for three different things: the agent deleting in
 * review, the agent discarding from the upload sheet, and an ENQUEUE FAILURE.
 * The first two are choices; the third is a lost photo. Subtracting all three
 * from "never arrived" would hide precisely the class of bug this page was built
 * to catch — a photo that vanished between the camera and the queue.
 */
class PhotoDropAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->property = Property::create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'title' => 'Sea-view', 'suburb' => 'Margate', 'property_type' => 'house',
            'listing_type' => 'sale', 'status' => 'active', 'price' => 100000,
        ]);
    }

    private function event(string $id, string $phase, ?array $meta = null): void
    {
        MobilePhotoEvent::create([
            'agency_id'        => $this->property->agency_id,
            'property_id'      => $this->property->id,
            'client_upload_id' => $id,
            'phase'            => $phase,
            'meta'             => $meta,
        ]);
    }

    private function summary(): array
    {
        $c = app(PhotoUploadDiagnosticsController::class);
        $m = (new ReflectionClass($c))->getMethod('summary');
        $m->setAccessible(true);

        return $m->invoke($c, $this->property->id);
    }

    public function test_an_agent_deletion_is_not_counted_as_a_lost_photo(): void
    {
        $this->event('a_1', 'captured');
        $this->event('a_1', 'dropped', ['reason' => 'removed_in_review']);

        $s = $this->summary();

        $this->assertSame(1, $s['captured']);
        $this->assertSame(1, $s['dropped']);
        $this->assertSame(0, $s['missing'], 'A photo the agent deleted is not missing.');
    }

    public function test_an_enqueue_failure_IS_counted_as_a_lost_photo(): void
    {
        $this->event('b_1', 'captured');
        $this->event('b_1', 'dropped', ['reason' => 'enqueue_failed']);

        $s = $this->summary();

        $this->assertSame(0, $s['dropped'], 'An enqueue failure is not an agent deletion.');
        $this->assertSame(1, $s['missing'], 'A photo lost before the queue must still read as missing.');
    }

    public function test_a_drop_with_no_reason_counts_as_a_loss(): void
    {
        // Fail safe. A new reason nobody has told this page about must not be
        // able to silently erase a loss.
        $this->event('c_1', 'captured');
        $this->event('c_1', 'dropped');

        $this->assertSame(1, $this->summary()['missing']);
    }

    public function test_the_dart_camelcase_spelling_is_accepted(): void
    {
        // The client is Dart; an enum's .name serialises camelCase. This list is
        // snake_case. An unmatched reason would make every deliberate deletion
        // read as a LOST photo — worse than the bug the reason was added to fix —
        // so the server matches spelling-insensitively rather than relying on a
        // convention holding across two repos.
        $this->event('e_1', 'captured');
        $this->event('e_1', 'dropped', ['reason' => 'removedInReview']);

        $s = $this->summary();

        $this->assertSame(1, $s['dropped']);
        $this->assertSame(0, $s['missing']);
    }

    public function test_an_unrecognised_reason_still_counts_as_a_loss(): void
    {
        $this->event('f_1', 'captured');
        $this->event('f_1', 'dropped', ['reason' => 'somethingNobodyToldUsAbout']);

        $this->assertSame(1, $this->summary()['missing']);
    }

    public function test_an_unresolved_bake_is_flagged_but_a_missing_one_is_not(): void
    {
        // Asymmetry with drop reasons, on purpose. A drop with NO reason is a
        // loss (a photo we cannot account for). A bake with NO value is fine —
        // it means an app build older than bake reporting, and flagging every
        // pre-telemetry photo as suspect would bury the real ones.
        $this->event('g_1', 'upload_ok', ['bake' => 'unknown']);
        $this->event('g_2', 'upload_ok', ['bake' => 'exif']);
        $this->event('g_3', 'upload_ok');                       // older build
        $this->event('g_4', 'upload_ok', ['bake' => 'sensor']);

        $s = $this->summary();

        $this->assertSame(1, $s['orientation_unconfirmed'], 'Only the explicit unknown counts.');
        $this->assertSame(1, $s['orientation_sensor_saved'],
            'A sensor rescue is worth counting — it is work nothing else could have done.');
    }

    public function test_a_photo_that_arrived_is_never_double_counted(): void
    {
        $this->event('d_1', 'captured');
        $this->event('d_1', 'dropped', ['reason' => 'removed_in_review']);
        $this->event('d_1', MobilePhotoEvent::PHASE_RECEIVED);

        $s = $this->summary();

        $this->assertSame(1, $s['received']);
        $this->assertSame(0, $s['dropped'], 'It arrived, so it is not a subtraction.');
        $this->assertSame(0, $s['missing']);
    }
}
