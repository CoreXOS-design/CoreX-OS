<?php

namespace App\Console\Commands;

use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Console\Command;

class PpSmokeTest extends Command
{
    protected $signature = 'pp:smoke-test';
    protected $description = 'Test Private Property sandbox SOAP connection by fetching branch details';

    public function handle(PrivatePropertySoapClient $client): int
    {
        $this->info('Connecting to Private Property sandbox...');
        $this->info('WSDL: ' . config('services.private_property.wsdl'));
        $this->info('Branch GUID: ' . config('services.private_property.branch_guid'));
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
        $this->info('Private Property sandbox connection confirmed.');

        return self::SUCCESS;
    }
}
