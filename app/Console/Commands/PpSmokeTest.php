<?php

namespace App\Console\Commands;

use App\Services\PrivateProperty\PrivatePropertyConfig;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Console\Command;

class PpSmokeTest extends Command
{
    protected $signature = 'pp:smoke-test {--agency= : Agency ID (uses env defaults when omitted)}';
    protected $description = 'Test Private Property sandbox SOAP connection by fetching branch details';

    public function handle(PrivatePropertySoapClient $client): int
    {
        $agency = $this->option('agency')
            ? \App\Models\Agency::find($this->option('agency'))
            : null;
        $client->forAgency($agency);
        $cfg = PrivatePropertyConfig::for($agency);
        $env = $cfg['sandbox'] ? 'sandbox' : 'production';
        $this->info("Connecting to Private Property ({$env})...");
        $this->info('WSDL: ' . $cfg['wsdl']);
        $this->info('Branch GUID: ' . $cfg['branch_guid']);
        $this->newLine();

        $result = $client->getBranchDetails();

        if (isset($result['error']) && $result['error'] === true) {
            $this->error('FAIL — SOAP fault: ' . ($result['message'] ?? 'Unknown error'));
            return self::FAILURE;
        }

        $this->info('PASS — Branch details received:');
        $this->newLine();

        // Pretty-print the response
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info("Private Property {$env} connection confirmed.");

        return self::SUCCESS;
    }
}
