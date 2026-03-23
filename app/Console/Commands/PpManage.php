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
        {action : Action to perform: submit, reactivate, deactivate, status, showday, register-agent, deactivate-agent, agent-image, list-agents, list-active, summary}
        {--property= : Property ID (for listing actions)}
        {--user= : User ID (for agent actions)}
        {--image-url= : Image URL (for agent-image action)}
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
            'list-agents'      => $this->listAgents($client),
            'list-active'      => $this->listActive($client),
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

            if ($user->profile_photo_path) {
                $imageUrl = $baseUrl . '/storage/' . $user->profile_photo_path;
            } else {
                $this->error('No --image-url provided and user has no profile_photo_path');
                return 1;
            }
        }

        $this->info("Uploading image for agent #{$user->id}: {$imageUrl}");
        $result = $service->uploadAgentImage($user, $imageUrl);

        $this->outputResult($result);
        return $result['success'] ? 0 : 1;
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
