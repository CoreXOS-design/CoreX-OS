<?php

namespace App\Console\Commands;

use App\Services\MarketDataSnapshotService;
use Illuminate\Console\Command;

class CaptureMarketSnapshot extends Command
{
    protected $signature = 'market-snapshot:capture {property_id}';
    protected $description = 'Manually capture a market-state snapshot for a property';

    public function handle(): int
    {
        $propertyId = (int) $this->argument('property_id');
        $service = app(MarketDataSnapshotService::class);

        $this->info("Capturing market snapshot for property #{$propertyId}...");
        $snapshot = $service->capturePropertySnapshot($propertyId, null, auth()->id());

        $this->info("Snapshot created (id={$snapshot->id}):");
        $this->line("  Recommended price: R " . number_format($snapshot->recommended_price_at_time ?? 0));
        $this->line("  Days on market: " . ($snapshot->days_on_market_at_time ?? 'N/A'));
        $data = $snapshot->market_data_snapshot;
        $this->line("  Comparable sales: " . count($data['comparable_sales'] ?? []));
        $this->line("  Comparable listings: " . count($data['comparable_listings'] ?? []));
        $this->line("  Area avg price: R " . number_format($data['area_average_price'] ?? 0));

        return 0;
    }
}
