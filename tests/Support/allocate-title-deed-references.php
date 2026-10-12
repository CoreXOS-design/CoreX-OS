<?php

/**
 * Test helper process (AT-450 round 4): boots the app against the SAME test database as its parent test and allocates title-deed reference
 * numbers for one agency, holding the counter lock for a few milliseconds each time so concurrent processes really collide.
 *
 * Usage: php tests/Support/allocate-title-deed-references.php <agency_id> <how_many>
 * Prints one allocated sequence number per line.
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $agencyId, $howMany] = $argv + [null, 0, 0];
$service = app(App\Services\TitleDeeds\TitleDeedReferenceService::class);

for ($i = 0; $i < (int) $howMany; $i++) {
    $number = Illuminate\Support\Facades\DB::transaction(function () use ($service, $agencyId) {
        [$sequence] = $service->allocate((int) $agencyId);
        usleep(random_int(5000, 25000));   // hold the lock: a second process must wait here, never read the same value

        return $sequence;
    }, 5);   // a deadlock (first allocation for a new agency) retries the whole transaction, as the app does
    echo $number . "\n";
}
