<?php

namespace Tests\Feature\PrivateProperty;

use App\Models\Agency;
use App\Services\PrivateProperty\PrivatePropertyConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the env-vs-agency precedence rules described in
 * .ai/specs/pp-syndication-per-agency.md acceptance criterion #5.
 */
class PrivatePropertyConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.private_property.username'       => 'env-user',
            'services.private_property.password'       => 'env-pass',
            'services.private_property.branch_guid'    => 'env-guid',
            'services.private_property.wsdl'           => 'https://env.example/wsdl',
            'services.private_property.sandbox'        => true,
            'services.private_property.image_base_url' => 'https://env.example/img',
            'services.private_property.webhook_secret' => 'env-secret',
        ]);
    }

    public function test_null_agency_returns_env_values(): void
    {
        $cfg = PrivatePropertyConfig::for(null);

        $this->assertSame('env-user', $cfg['username']);
        $this->assertSame('env-pass', $cfg['password']);
        $this->assertSame('env-guid', $cfg['branch_guid']);
        $this->assertSame('env-secret', $cfg['webhook_secret']);
        $this->assertNull($cfg['source_agency_id']);
    }

    public function test_agency_values_override_env(): void
    {
        $agency = Agency::create([
            'name'              => 'PP Override',
            'slug'              => 'pp-override',
            'pp_enabled'        => true,
            'pp_username'       => 'agency-user',
            'pp_password'       => 'agency-pass',
            'pp_branch_guid'    => 'agency-guid',
            'pp_wsdl'           => 'https://agency.example/wsdl',
            'pp_sandbox'        => false,
            'pp_image_base_url' => 'https://agency.example/img',
            'pp_webhook_secret' => 'agency-secret',
        ]);

        $cfg = PrivatePropertyConfig::for($agency->fresh());

        $this->assertSame('agency-user', $cfg['username']);
        $this->assertSame('agency-pass', $cfg['password']);
        $this->assertSame('agency-guid', $cfg['branch_guid']);
        $this->assertSame('https://agency.example/wsdl', $cfg['wsdl']);
        $this->assertFalse($cfg['sandbox']);
        $this->assertSame('https://agency.example/img', $cfg['image_base_url']);
        $this->assertSame('agency-secret', $cfg['webhook_secret']);
        $this->assertSame($agency->id, $cfg['source_agency_id']);
    }

    public function test_blank_agency_values_fall_back_to_env(): void
    {
        $agency = Agency::create([
            'name' => 'PP Blank', 'slug' => 'pp-blank',
        ]);

        $cfg = PrivatePropertyConfig::for($agency);

        $this->assertSame('env-user', $cfg['username']);
        $this->assertSame('env-pass', $cfg['password']);
        $this->assertSame('env-guid', $cfg['branch_guid']);
        $this->assertSame('env-secret', $cfg['webhook_secret']);
    }

    public function test_agency_for_branch_guid_lookup(): void
    {
        $a = Agency::create(['name' => 'A', 'slug' => 'a', 'pp_branch_guid' => 'guid-a']);
        $b = Agency::create(['name' => 'B', 'slug' => 'b', 'pp_branch_guid' => 'guid-b']);

        $this->assertSame($a->id, PrivatePropertyConfig::agencyForBranchGuid('guid-a')?->id);
        $this->assertSame($b->id, PrivatePropertyConfig::agencyForBranchGuid('guid-b')?->id);
        $this->assertNull(PrivatePropertyConfig::agencyForBranchGuid('nope'));
        $this->assertNull(PrivatePropertyConfig::agencyForBranchGuid(null));
    }
}
