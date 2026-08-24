<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Images\AgentPhotoNormalizer;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Regression coverage for the agent #47 (Shalan) investigation, 2026-08-03:
 *
 *  1. PP's UpdateAgentImage answers a rejected (non-JPG) image with a normal
 *     200 response, not a SoapFault. uploadAgentImage() must read
 *     UpdateAgentImageResult and only treat the confirmed success string
 *     "image saved" (verified live against the PP sandbox 2026-08-03) as
 *     success — 145/145 historical calls were silently logged as success
 *     because only the transport-level error flag was checked.
 *  2. CoreX stores agent photos as WebP; PP only accepts JPG. submitAgentImages()
 *     must push the JPEG rendition (AgentPhotoNormalizer::ensureJpeg), not
 *     agent_photo_path.
 *  3. registerAgent() must persist pp_unique_agent_id/pp_external_ref after a
 *     successful UpdateAgent (via a GetAgent lookup), and must not repeat that
 *     lookup once the identity is already known.
 *
 * See .ai/specs/private-property.md §7a/§7b.
 */
class PrivatePropertyAgentImageTest extends TestCase
{
    use RefreshDatabase;

    private function service(PrivatePropertySoapClient $client): PrivatePropertySyndicationService
    {
        return new PrivatePropertySyndicationService(
            $client,
            new PrivatePropertyListingMapper(),
            new AgentPhotoNormalizer()
        );
    }

    public function test_upload_agent_image_reports_failure_when_pp_rejects_the_format(): void
    {
        $user = User::factory()->create(['name' => 'Shalan Du Bois', 'cell' => '0716028661']);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('updateAgentImage')->once()->andReturn([
            'UpdateAgentImageResult' => 'only jpg images are supported',
        ]);

        $result = $this->service($client)->uploadAgentImage($user, 'https://corexos.co.za/storage/agents/47/photo.webp');

        $this->assertFalse($result['success'], 'A PP-side rejection must not be reported as a success.');
        $this->assertSame('only jpg images are supported', $result['message']);
    }

    public function test_upload_agent_image_reports_success_only_on_the_literal_successful_ack(): void
    {
        $user = User::factory()->create(['cell' => '0716028661']);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('updateAgentImage')->once()->andReturn([
            'UpdateAgentImageResult' => 'image saved',
        ]);

        $result = $this->service($client)->uploadAgentImage($user, 'https://corexos.co.za/storage/agents/1/photo.jpg');

        $this->assertTrue($result['success']);
    }

    public function test_submit_agent_images_pushes_the_jpeg_rendition_not_the_webp_path(): void
    {
        Storage::fake('public');

        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
            'cell' => '0716028661',
        ]);

        // Only the WebP exists on disk — no JPEG rendition yet (pre-fix agent).
        $image = UploadedFile::fake()->image('photo.jpg', 1200, 1200);
        $webpPath = (new AgentPhotoNormalizer())->store($image, $user->id);
        Storage::disk('public')->delete("agents/{$user->id}/photo.jpg");
        $this->assertFalse(Storage::disk('public')->exists("agents/{$user->id}/photo.jpg"));
        $user->forceFill(['agent_photo_path' => $webpPath])->save();

        $property = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Test listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
        ]);

        $seenUrl = null;
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('updateAgentImage')->once()
            ->andReturnUsing(function ($agentData, $imageUrl) use (&$seenUrl) {
                $seenUrl = $imageUrl;
                return ['UpdateAgentImageResult' => 'image saved'];
            });

        $result = $this->service($client)->submitAgentImages($property);

        $this->assertNotNull($seenUrl);
        $this->assertStringEndsWith("/storage/agents/{$user->id}/photo.jpg", $seenUrl);
        $this->assertTrue(Storage::disk('public')->exists("agents/{$user->id}/photo.jpg"), 'ensureJpeg() must have lazily regenerated the missing rendition.');
        $this->assertCount(1, $result['submitted']);
        $this->assertEmpty($result['errors']);
    }

    public function test_register_agent_persists_pp_identity_after_a_successful_update_agent(): void
    {
        $user = User::factory()->create(['cell' => '0716028661', 'pp_unique_agent_id' => null, 'pp_external_ref' => null]);

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('getAllAgentsForBranch')->andReturn(['GetAllAgentsForBranchResult' => ['any' => '']]);
        $client->shouldReceive('updateAgent')->once()->andReturn(['UpdateAgentResult' => 'Successful']);
        $client->shouldReceive('getAgent')->once()->with((string) $user->id)
            ->andReturn(['PrivatePropertyAgentId' => 'ENC123==']);

        $result = $this->service($client)->registerAgent($user);

        $this->assertTrue($result['success']);
        $user->refresh();
        $this->assertSame('ENC123==', $user->pp_unique_agent_id);
        $this->assertSame((string) $user->id, $user->pp_external_ref);
    }

    public function test_register_agent_does_not_repeat_the_get_agent_lookup_once_identity_is_known(): void
    {
        $user = User::factory()->create([
            'cell' => '0716028661',
            'pp_unique_agent_id' => 'ENC123==',
            'pp_external_ref' => null,
        ]);
        $user->forceFill(['pp_external_ref' => (string) $user->id])->save();

        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('getAllAgentsForBranch')->andReturn(['GetAllAgentsForBranchResult' => ['any' => '']]);
        $client->shouldReceive('updateAgent')->once()->andReturn(['UpdateAgentResult' => 'Successful']);
        $client->shouldNotReceive('getAgent');

        $result = $this->service($client)->registerAgent($user);

        $this->assertTrue($result['success']);
    }
}
