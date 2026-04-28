<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Console\Command;

class PpManage extends Command
{
    protected $signature = 'pp:manage
        {action : Action to perform: submit, reactivate, deactivate, status, showday, register-agent, deactivate-agent, agent-image, submit-agent-images, list-agents, list-active, summary, update-agent-id, update-listing-id, add-video, set-listing-uuid, test-webhook}
        {--uuid= : PP internal listing UUID (for set-listing-uuid)}
        {--property= : Property ID (for listing actions)}
        {--user= : User ID (for agent actions)}
        {--image-url= : Image URL (for agent-image action)}
        {--pp-agent-id= : PP encrypted agent ID (for update-agent-id)}
        {--pp-listing-id= : PP encrypted listing ID (for update-listing-id)}
        {--youtube= : YouTube video ID, exactly 11 chars (for add-video)}
        {--matterport= : Matterport ID (for add-video)}
        {--start= : Showday start datetime (Y-m-d H:i)}
        {--end= : Showday end datetime (Y-m-d H:i)}
        {--description= : Showday description}';

    protected $description = 'Private Property integration management — submit, reactivate, agents, showdays, etc.';

    public function handle(
        PrivatePropertySyndicationService $service,
        PrivatePropertySoapClient $client,
        PrivatePropertyListingMapper $mapper
    ): int {
        $action = $this->argument('action');

        return match ($action) {
            'submit'           => $this->submitListing($service, $mapper),
            'reactivate'       => $this->reactivateListing($service),
            'deactivate'       => $this->deactivateListing($service),
            'status'           => $this->checkStatus($client),
            'summary'          => $this->listingSummary($client),
            'showday'          => $this->submitShowday($service),
            'register-agent'   => $this->registerAgent($service),
            'deactivate-agent' => $this->deactivateAgent($service),
            'agent-image'      => $this->uploadAgentImage($service),
            'submit-agent-images' => $this->submitAgentImages($service),
            'list-agents'      => $this->listAgents($client),
            'list-active'      => $this->listActive($client),
            'update-agent-id'  => $this->updateAgentId($service),
            'update-listing-id'=> $this->updateListingId($service),
            'add-video'        => $this->addVideo($service),
            'set-listing-uuid' => $this->setListingUuid(),
            'test-webhook'     => $this->testWebhook(),
            default            => $this->error("Unknown action: {$action}") ?? 1,
        };
    }

    private function submitListing(PrivatePropertySyndicationService $service, PrivatePropertyListingMapper $mapper): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $this->info("Pre-flight check for property #{$property->id}...");
        $missing = $mapper->checkReadiness($property);
        if (!empty($missing)) {
            $this->error('Missing required fields:');
            foreach ($missing as $m) {
                $this->line("  - {$m['label']} ({$m['tab']} tab)");
            }
            return 1;
        }

        $this->info("Submitting property #{$property->id} to PP...");
        $result = $service->submitListing($property);

        if ($result['success']) {
            $this->info("SUCCESS: {$result['message']}");
            $this->line("Status: {$property->fresh()->pp_syndication_status}");
            $this->line("PP Ref: " . ($property->fresh()->pp_ref ?: 'pending'));
        } else {
            $this->error("FAILED: {$result['message']}");
        }

        return $result['success'] ? 0 : 1;
    }

    private function reactivateListing(PrivatePropertySyndicationService $service): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $this->info("Reactivating property #{$property->id} on PP...");
        $result = $service->reactivateListing($property);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function deactivateListing(PrivatePropertySyndicationService $service): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $this->info("Deactivating property #{$property->id} on PP...");
        $result = $service->deactivateListing($property);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function checkStatus(PrivatePropertySoapClient $client): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $this->info("Checking status for property #{$property->id}...");

        $status = $client->getListingStatus((string) $property->id);
        $this->line("Status: " . json_encode($status, JSON_PRETTY_PRINT));

        $ref = $client->getReferenceNumber((string) $property->id,
            in_array(strtolower($property->mandate_type ?? ''), ['rental']) ? 'Rental' : 'Sale'
        );
        $this->line("Reference: " . json_encode($ref, JSON_PRETTY_PRINT));

        return 0;
    }

    private function listingSummary(PrivatePropertySoapClient $client): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $this->info("Getting listing summary for property #{$property->id}...");
        $result = $client->getListingSummary((string) $property->id);
        $this->line(json_encode($result, JSON_PRETTY_PRINT));

        return 0;
    }

    private function submitShowday(PrivatePropertySyndicationService $service): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $start = $this->option('start');
        $end   = $this->option('end');

        if (!$start || !$end) {
            $this->error('--start and --end are required. Format: "Y-m-d H:i"');
            return 1;
        }

        $this->info("Submitting showday for property #{$property->id}...");
        $result = $service->submitShowday($property, [
            'start_date'  => \Carbon\Carbon::parse($start)->format('Y-m-d\TH:i:s'),
            'end_date'    => \Carbon\Carbon::parse($end)->format('Y-m-d\TH:i:s'),
            'description' => $this->option('description') ?? 'Open Showday',
        ]);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function registerAgent(PrivatePropertySyndicationService $service): int
    {
        $user = $this->getUser();
        if (!$user) return 1;

        $this->info("Registering agent #{$user->id} ({$user->name}) on PP...");
        $result = $service->registerAgent($user);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function deactivateAgent(PrivatePropertySyndicationService $service): int
    {
        $user = $this->getUser();
        if (!$user) return 1;

        $this->info("Deactivating agent #{$user->id} ({$user->name}) on PP...");
        $result = $service->registerAgent($user, false);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function uploadAgentImage(PrivatePropertySyndicationService $service): int
    {
        $user = $this->getUser();
        if (!$user) return 1;

        $imageUrl = $this->option('image-url');
        if (!$imageUrl) {
            // Try to build from user's profile photo
            $override  = config('services.private_property.image_base_url');
            $baseUrl   = rtrim(!empty($override) ? $override : config('app.url'), '/');

            if ($user->agent_photo_path) {
                $imageUrl = $baseUrl . '/storage/' . $user->agent_photo_path;
            } else {
                $this->error('No --image-url provided and user has no agent_photo_path');
                return 1;
            }
        }

        $this->info("Uploading image for agent #{$user->id}: {$imageUrl}");
        $result = $service->uploadAgentImage($user, $imageUrl);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function submitAgentImages(PrivatePropertySyndicationService $service): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $this->info("Submitting agent images for property #{$property->id}...");
        $result = $service->submitAgentImages($property);

        if (!empty($result['submitted'])) {
            $this->info('Submitted:');
            foreach ($result['submitted'] as $entry) {
                $this->line("  ✓ Agent #{$entry['user_id']} ({$entry['name']}) — {$entry['url']}");
            }
        }

        if (!empty($result['skipped'])) {
            $this->warn('Skipped (no photo):');
            foreach ($result['skipped'] as $entry) {
                $this->line("  - Agent #{$entry['user_id']} ({$entry['name']}) — {$entry['reason']}");
            }
        }

        if (!empty($result['errors'])) {
            $this->error('Errors:');
            foreach ($result['errors'] as $entry) {
                $this->line("  ✗ Agent #{$entry['user_id']} ({$entry['name']}) — {$entry['message']}");
            }
        }

        $total = count($result['submitted'] ?? []);
        $this->info("Done. {$total} agent image(s) submitted.");

        return empty($result['errors']) ? 0 : 1;
    }

    private function listAgents(PrivatePropertySoapClient $client): int
    {
        $this->info("Fetching all agents on PP branch...");
        $result = $client->getAllAgentsForBranch();
        $this->line(json_encode($result, JSON_PRETTY_PRINT));
        return 0;
    }

    private function listActive(PrivatePropertySoapClient $client): int
    {
        $this->info("Fetching active listings on PP branch...");
        $result = $client->getActiveListings();
        $this->line(json_encode($result, JSON_PRETTY_PRINT));
        return 0;
    }

    private function updateAgentId(PrivatePropertySyndicationService $service): int
    {
        $user = $this->getUser();
        if (!$user) return 1;

        $ppAgentId = $this->option('pp-agent-id');
        if (!$ppAgentId) {
            $this->error('--pp-agent-id is required');
            return 1;
        }

        $this->info("Updating PP agent ID for user #{$user->id} ({$user->name})...");
        $result = $service->updateUniqueAgentId($user, $ppAgentId);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function updateListingId(PrivatePropertySyndicationService $service): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $ppListingId = $this->option('pp-listing-id');
        if (!$ppListingId) {
            $this->error('--pp-listing-id is required');
            return 1;
        }

        $this->info("Updating PP listing ID for property #{$property->id}...");
        $result = $service->updateUniqueListingId($property, $ppListingId);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function addVideo(PrivatePropertySyndicationService $service): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $youtube   = $this->option('youtube');
        $matterport = $this->option('matterport');

        if (!$youtube && !$matterport) {
            $this->error('At least one of --youtube or --matterport is required');
            return 1;
        }

        if ($youtube && strlen($youtube) !== 11) {
            $this->error("YouTube ID must be exactly 11 characters. Got: " . strlen($youtube));
            return 1;
        }

        // Save to property record
        $property->update(array_filter([
            'youtube_video_id' => $youtube,
            'matterport_id'    => $matterport,
        ], fn($v) => $v !== null));
        $property->refresh();

        $this->info("Pushing video/Matterport for property #{$property->id}...");
        $result = $service->pushVideoOrMatterport($property);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
    }

    private function setListingUuid(): int
    {
        $property = $this->getProperty();
        if (!$property) return 1;

        $uuid = trim((string) $this->option('uuid'));
        if ($uuid === '') {
            $this->error('--uuid=UUID is required');
            return 1;
        }

        // PP internal listing UUIDs are GUID-shaped (8-4-4-4-12 hex with hyphens, ~36 chars).
        // Be permissive in case PP varies the format slightly — require hyphens and length 16-64.
        if (!str_contains($uuid, '-') || strlen($uuid) < 16 || strlen($uuid) > 64) {
            $this->error("UUID does not look valid (got: {$uuid}). Expected hyphenated string, 16-64 chars.");
            return 1;
        }

        $property->update(['pp_listing_feed_ref' => $uuid]);
        $property->refresh();

        $this->info("SUCCESS: Property #{$property->id} pp_listing_feed_ref set.");
        $this->line("  pp_ref:              " . ($property->pp_ref ?? 'null'));
        $this->line("  pp_listing_feed_ref: {$property->pp_listing_feed_ref}");
        return 0;
    }

    private function testWebhook(): int
    {
        $secret = config('services.private_property.webhook_secret');
        if (empty($secret)) {
            $this->error('PP_WEBHOOK_SECRET is not set in .env — cannot generate a valid signature.');
            return 1;
        }

        $payload = [
            'messageType'              => 'Lead',
            'leadId'                   => 'TEST-' . uniqid(),
            'leadType'                 => 'Enquiry',
            'agencyId'                 => 'HFC',
            'listingId'                => 'TEST-LISTING',
            'listingType'              => 'Sale',
            'listingReference'         => 'T0000000',
            'listingExternalReference' => '16',
            'listingAddress'           => '14 Ocean Drive',
            'listingSuburb'            => 'Uvongo',
            'leadDateTime'             => now()->toIso8601String(),
            'leadName'                 => 'Test Lead',
            'leadPhoneNumber'          => '+27821234567',
            'leadEmail'                => 'test@example.com',
            'leadMessage'              => 'pp:manage test-webhook diagnostic payload',
        ];
        $body      = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $url = rtrim(config('app.url'), '/') . '/api/pp/webhook';
        $this->info("POST {$url}");

        try {
            $resp = \Illuminate\Support\Facades\Http::withHeaders([
                'X-Signature'  => $signature,
                'Content-Type' => 'application/json',
            ])->withBody($body, 'application/json')->post($url);

            $this->line("HTTP {$resp->status()}: {$resp->body()}");
            return $resp->successful() ? 0 : 1;
        } catch (\Throwable $e) {
            $this->error("Request failed: {$e->getMessage()}");
            return 1;
        }
    }

    private function getProperty(): ?Property
    {
        $id = $this->option('property');
        if (!$id) {
            $this->error('--property=ID is required');
            return null;
        }

        $property = Property::find($id);
        if (!$property) {
            $this->error("Property #{$id} not found");
            return null;
        }

        return $property;
    }

    private function getUser(): ?User
    {
        $id = $this->option('user');
        if (!$id) {
            $this->error('--user=ID is required');
            return null;
        }

        $user = User::find($id);
        if (!$user) {
            $this->error("User #{$id} not found");
            return null;
        }

        return $user;
    }

    private function outputResult(array $result): void
    {
        if ($result['success']) {
            $this->info("SUCCESS: {$result['message']}");
        } else {
            $this->error("FAILED: {$result['message']}");
        }

        if (isset($result['result'])) {
            $this->line(json_encode($result['result'], JSON_PRETTY_PRINT));
        }
    }
}
