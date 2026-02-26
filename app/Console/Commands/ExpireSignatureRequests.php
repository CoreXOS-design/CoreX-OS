<?php

namespace App\Console\Commands;

use App\Services\Docuperfect\SignatureService;
use Illuminate\Console\Command;

class ExpireSignatureRequests extends Command
{
    protected $signature = 'signatures:expire';

    protected $description = 'Expire outstanding signature requests past their token expiry date';

    public function handle(SignatureService $signatureService): int
    {
        $this->info('Checking for expired signature requests...');

        $count = $signatureService->expireOutstandingRequests();

        $this->info("Done. Expired: {$count}");

        return 0;
    }
}
