<?php

namespace App\Console\Commands;

use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class P24SyndicateSmokeTest extends Command
{
    protected $signature = 'p24:syndicate-smoke-test';
    protected $description = 'Test connectivity to the Property24 ExDev API';

    public function handle(Property24ApiClient $client): int
    {
        $this->info('[P24 Smoke Test] Testing ExDev API connectivity...');
        $this->line('Environment: ' . ($client->isSandbox() ? 'SANDBOX' : 'PRODUCTION'));
        $this->line('Agency ID: ' . ($client->getAgencyId() ?: '(not set)'));
        $this->line('API URL: ' . config('services.property24_syndication.api_url'));
        $this->line('Username: ' . config('services.property24_syndication.username'));
        $this->line('');

        if (empty(config('services.property24_syndication.username'))) {
            $this->error('P24_EXDEV_USERNAME is not set in .env');
            return self::FAILURE;
        }

        $this->info('Step 1: Testing basic connectivity (echo)...');
        $url = config('services.property24_syndication.api_url') . '/listing/' . config('services.property24_syndication.api_version') . '/echo?stringToEcho=CoreX+smoke+test';
        try {
            $response = Http::get($url);
            if ($response->successful()) { $this->line("Echo response: " . $response->body()); }
            else { $this->error("Echo failed: HTTP " . $response->status()); return self::FAILURE; }
        } catch (\Exception $e) { $this->error("Echo failed: " . $e->getMessage()); return self::FAILURE; }

        $this->info('Step 2: Testing authentication (echo-authenticated)...');
        $result = $client->smokeTest();
        if ($result['success']) {
            $this->info('[P24 Smoke Test] SUCCESS — Authentication verified.');
            $this->line("Response: " . ($result['data']['raw'] ?? json_encode($result['data'])));
        } else {
            $this->error('[P24 Smoke Test] FAILED — ' . ($result['message'] ?? 'Unknown error'));
            return self::FAILURE;
        }

        $this->info('Step 3: Fetching agency details...');
        $agencyResult = $client->getAgency();
        if ($agencyResult['success']) {
            $this->info('Agency found:');
            foreach ($agencyResult['data'] ?? [] as $key => $value) {
                if (is_scalar($value)) $this->line("  {$key}: {$value}");
            }
        } else {
            $this->warn('Could not fetch agency: ' . ($agencyResult['message'] ?? 'Unknown'));
        }

        $this->line('');
        $this->info('[P24 Smoke Test] All checks passed.');
        return self::SUCCESS;
    }
}
