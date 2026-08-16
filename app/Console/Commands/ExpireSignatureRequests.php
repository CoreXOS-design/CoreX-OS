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

        // Track C (HD-11) — the LEGAL clock, distinct from the link TTL above. A ceremony whose
        // mandate deadline has passed is transitioned to a recorded 'lapsed' state (never a silent
        // expiry). The pen is already stopped by isLapsed() (HD-10); this makes the lapse visible on
        // the tracker and the evidence timeline.
        $lapsed = $signatureService->lapseExpiredCeremonies();

        $this->info("Done. Expired: {$count}. Lapsed: {$lapsed}.");

        return 0;
    }
}
