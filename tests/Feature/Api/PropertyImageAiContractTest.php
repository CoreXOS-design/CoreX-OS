<?php

namespace Tests\Feature\Api;

use App\Jobs\AnalysePropertyImageJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyImageAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Locks the JSON contract the mobile app's AI image-recognition feature
 * parses literally. A snake_case drift on any of these keys reads as
 * `undefined` on the client and silently shows nothing — exactly the
 * failure investigated in the "AI suggestions returned no features" report.
 *
 * Covers the three breaks found in that trace:
 *   1. GET /v1/mobile/features must return `aiImageRecognition` (camelCase).
 *   5. GET .../ai-suggestions `sources[]` must use `analysisId` + `imagePath`.
 *   3. Upload must enqueue analysis on the DEFAULT queue (the only queue the
 *      production worker drains), not a dedicated `ai` queue.
 *
 * Spec: .ai/specs/property-image-recognition.md
 */
class PropertyImageAiContractTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name' => 'Coastal Realty',
            'slug' => 'coastal-realty',
            'ai_image_recognition_enabled' => true,
        ]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);

        $this->property = Property::create([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->user->id,
            'branch_id'     => $branch->id,
            'title'         => 'Sea-view 3 bed',
            'suburb'        => 'Uvongo',
            'property_type' => 'house',
            'status'        => 'active',
            'price'         => 2495000,
        ]);
    }

    /** Step 1 — the feature flag the app reads to render the AI section. */
    public function test_features_endpoint_returns_camelcase_ai_image_recognition_key(): void
    {
        $res = $this->actingAs($this->user)->getJson('/api/v1/mobile/features');

        $res->assertOk()
            ->assertJsonStructure(['aiVoice', 'aiImageRecognition', 'agencyId', 'userId']);

        // The exact key the client reads, and it reflects the agency flag.
        $this->assertTrue($res->json('aiImageRecognition'));
        // The old snake_case key must NOT be what we emit (would read undefined).
        $this->assertArrayNotHasKey('ai_image_recognition', $res->json());
    }

    /** Step 3 — analysis is enqueued on the default queue, and analysis_id is returned. */
    public function test_upload_enqueues_analysis_on_default_queue_and_returns_id(): void
    {
        Queue::fake();
        Storage::fake('public');

        $res = $this->actingAs($this->user)->postJson(
            "/api/v1/mobile/properties/{$this->property->id}/images",
            ['image' => UploadedFile::fake()->image('lounge.jpg', 800, 600)]
        );

        $res->assertCreated()->assertJsonStructure(['url', 'analysis_id']);
        $this->assertNotNull($res->json('analysis_id'));

        Queue::assertPushed(AnalysePropertyImageJob::class, function ($job) {
            // null queue === the connection default; anything else (e.g. 'ai')
            // is a queue the production worker does not drain.
            return $job->queue === null || $job->queue === 'default';
        });
    }

    /** Step 5 — the polled suggestions JSON the client parses literally. */
    public function test_ai_suggestions_sources_use_camelcase_keys(): void
    {
        PropertyImageAnalysis::create([
            'agency_id'         => $this->agency->id,
            'property_id'       => $this->property->id,
            'image_path'        => 'properties/' . $this->property->id . '/lounge.jpg',
            'status'            => 'complete',
            'detected_features' => [['token' => 'pool', 'confidence' => 0.92]],
            'detected_spaces'   => [['token' => 'Lounge', 'confidence' => 0.81]],
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$this->property->id}/ai-suggestions");

        $res->assertOk()
            ->assertJsonStructure([
                'queued', 'processing', 'complete', 'failed',
                'features' => [['token', 'confidence', 'sources' => [['analysisId', 'imagePath', 'confidence']]]],
                'spaces'   => [['token', 'confidence', 'sources' => [['analysisId', 'imagePath', 'confidence']]]],
            ]);

        $source = $res->json('features.0.sources.0');
        $this->assertArrayHasKey('analysisId', $source);
        $this->assertArrayHasKey('imagePath', $source);
        $this->assertArrayNotHasKey('analysis_id', $source);
        $this->assertArrayNotHasKey('image_path', $source);
    }
}
