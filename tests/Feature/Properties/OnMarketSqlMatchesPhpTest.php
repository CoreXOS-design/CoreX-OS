<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Compliance\PpraMandateRegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 audit finding 5 — Property::isOnMarket() normalises the status (trim, lower-case,
 * spaces -> underscores) but the SQL side compared raw strings, so "Let Out" / " SOLD " counted
 * as live in every scope and query. One normalised SQL expression now backs scopeOnMarket(), the
 * PPRA register query and the other OFF_MARKET_STATUSES queries, so the two sides cannot disagree.
 */
final class OnMarketSqlMatchesPhpTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        foreach (['agent', 'branch_manager'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'access_properties', 'agency_id' => $this->agency->id]);
        }
    }

    private function make(string $status, string $address): Property
    {
        // The observer refuses an out-of-vocabulary status on save, but the P24 importer / legacy
        // rows / raw edits do land display-cased values in the table — so write the odd spelling
        // straight to the row, exactly as it arrives in production.
        $property = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'address' => $address,
            'suburb' => 'Uvongo', 'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active',
            'price' => 1500000, 'beds' => 2, 'baths' => 1, 'garages' => 0,
            'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]);
        DB::table('properties')->where('id', $property->id)->update(['status' => $status]);

        return Property::withoutGlobalScope(AgencyScope::class)->find($property->id);
    }

    /** @return array<string, string> label => the way the importer / a stray edit can write it */
    private function offMarketSpellings(): array
    {
        return [
            'let_out_label' => 'Let Out',
            'padded_upper'  => ' SOLD ',
            'tab_free_pad'  => ' withdrawn',
            'third_party'   => 'Sold By 3rd Party',
            'plain'         => 'sold',
        ];
    }

    public function test_scope_on_market_excludes_exactly_what_is_on_market_says_is_off_market(): void
    {
        $live = [
            $this->make('active', '1 Live Rd'),
            $this->make('For Sale', '2 Live Rd'),   // display-cased, spaces: not off-market
            $this->make('under_offer', '3 Live Rd'),
        ];
        $dead = [];
        foreach ($this->offMarketSpellings() as $label => $status) {
            $dead[] = $this->make($status, "{$label} Rd");
        }

        $onMarketIds = Property::withoutGlobalScope(AgencyScope::class)->onMarket()->pluck('id')->all();

        foreach ($live as $p) {
            $this->assertContains($p->id, $onMarketIds, "'{$p->status}' must be on-market in SQL");
            $this->assertTrue($p->fresh()->isOnMarket());
        }
        foreach ($dead as $p) {
            $this->assertNotContains($p->id, $onMarketIds, "'{$p->status}' must be off-market in SQL");
            $this->assertFalse($p->fresh()->isOnMarket(), "PHP agrees for '{$p->status}'");
        }

        // The complement scope partitions the table with no row on both or neither.
        $offIds = Property::withoutGlobalScope(AgencyScope::class)->offMarket()->pluck('id')->all();
        $this->assertSame([], array_intersect($onMarketIds, $offIds));
        $this->assertCount(
            Property::withoutGlobalScope(AgencyScope::class)->count(),
            array_merge($onMarketIds, $offIds)
        );
    }

    public function test_the_ppra_mandate_register_query_leaves_out_let_out_and_padded_sold(): void
    {
        $live = $this->make('active', '1 Live Street');
        $this->make('Let Out', '2 Let Street');
        $this->make(' SOLD ', '3 Sold Street');

        $rows = app(PpraMandateRegisterService::class)->search($this->agency, ['advertised_only' => '0']);
        $ids  = collect($rows->items())->map(fn ($row) => $row->property->id)->all();

        $this->assertSame([$live->id], $ids);
    }

    public function test_the_query_builder_helpers_work_on_a_plain_db_table_query(): void
    {
        $live = $this->make('active', '1 Live Rd');
        $dead = $this->make(' Let Out', '2 Dead Rd');

        $on  = Property::constrainOnMarket(DB::table('properties'))->pluck('id')->all();
        $off = Property::constrainOffMarket(DB::table('properties'))->pluck('id')->all();

        $this->assertSame([$live->id], $on);
        $this->assertSame([$dead->id], $off);
        // $except leaves a status out of the off-market set (the map keeps Sold on its own layer)
        $this->assertSame([], Property::constrainOffMarket(DB::table('properties'), 'status', ['let_out'])->pluck('id')->all());
    }

    public function test_the_column_name_is_validated_before_it_reaches_raw_sql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Property::normalisedStatusSql('status); DROP TABLE properties; --');
    }
}
