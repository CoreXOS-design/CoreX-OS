<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OutboundMailGuard;
use Tests\TestCase;

/**
 * AT-URGENT-2026-09-08 — proves isProductionConfirmed()/isActive() are
 * untouched by the ImapSentFolderAppender guard fix: still exactly the
 * hardcoded APP_ENV + APP_URL host check, both branches.
 */
final class OutboundMailGuardTest extends TestCase
{
    public function test_is_active_when_env_is_not_production(): void
    {
        config(['app.env' => 'local', 'app.url' => 'https://qatesting1.corexos.co.za']);

        $this->assertFalse(OutboundMailGuard::isProductionConfirmed());
        $this->assertTrue(OutboundMailGuard::isActive());
    }

    public function test_is_active_when_env_is_production_but_host_is_not_the_live_host(): void
    {
        // e.g. the demo box, which runs APP_ENV=production but is not corexos.co.za.
        config(['app.env' => 'production', 'app.url' => 'https://demo1.corexos.co.za']);

        $this->assertFalse(OutboundMailGuard::isProductionConfirmed());
        $this->assertTrue(OutboundMailGuard::isActive());
    }

    public function test_is_not_active_when_env_is_production_and_host_is_the_live_host(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);

        $this->assertTrue(OutboundMailGuard::isProductionConfirmed());
        $this->assertFalse(OutboundMailGuard::isActive());
    }

    public function test_is_not_active_when_env_is_production_and_host_is_the_www_live_host(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://www.corexos.co.za']);

        $this->assertTrue(OutboundMailGuard::isProductionConfirmed());
        $this->assertFalse(OutboundMailGuard::isActive());
    }
}
