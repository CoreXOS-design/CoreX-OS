<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertyTokenService;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A single-agent account can never talk to a property portal - not to write, not to read.
 * .ai/specs/solo-agent-accounts.md section 5. Guard lives in Property24ApiClient::request() and
 * PrivatePropertySoapClient::call(), the two points every portal call passes through.
 */
class SoloAgentPortalBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.property24_syndication.api_url' => 'https://p24.test']);
    }

    private function agency(string $slug, bool $solo): Agency
    {
        $agency = Agency::create(['name' => ucfirst($slug), 'slug' => $slug]);
        $agency->forceFill([
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
            'account_kind' => $solo ? Agency::ACCOUNT_KIND_SOLO : Agency::ACCOUNT_KIND_AGENCY,
        ])->save();

        return $agency->fresh();
    }

    public function test_p24_refuses_a_solo_agency_write_and_sends_nothing(): void
    {
        Http::fake();

        $result = (new Property24ApiClient($this->agency('lone-agent', true)))->saveListing(1, ['x' => 1]);

        $this->assertTrue($result['refused'] ?? false);
        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_p24_refuses_a_solo_agency_read_too(): void
    {
        Http::fake();

        $result = (new Property24ApiClient($this->agency('lone-agent', true)))->isOnPortal(1, 2);

        $this->assertNothingReachedP24($result);
    }

    public function test_p24_still_sends_for_a_normal_agency(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // A read with no property id, so nothing is logged against a property row: the control is only that the call goes out.
        (new Property24ApiClient($this->agency('big-agency', false)))->getCountries();

        Http::assertSentCount(1);
    }

    public function test_private_property_refuses_a_solo_agency_and_makes_no_soap_call(): void
    {
        $client = (new PrivatePropertySoapClient(app(PrivatePropertyTokenService::class)))
            ->forAgency($this->agency('lone-agent', true));

        $result = $client->call('GetBranchDetails', []);

        $this->assertTrue($result['refused'] ?? false);
        $this->assertTrue($result['error']);
    }

    private function assertNothingReachedP24(array $result): void
    {
        Http::assertNothingSent();
        $this->assertNotSame(true, $result['success'] ?? null);
    }
}
