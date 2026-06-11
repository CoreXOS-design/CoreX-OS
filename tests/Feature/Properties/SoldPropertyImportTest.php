<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\Properties\SoldPropertyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class SoldPropertyImportTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $owner;
    private User $agent;
    private User $otherAgent;

    protected function setUp(): void
    {
        parent::setUp();

        Role::query()->insert([
            ['name' => 'super_admin', 'label' => 'Super Admin', 'is_owner' => true,  'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'agent',       'label' => 'Agent',       'is_owner' => false, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Role::clearCache();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch       = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->owner = User::factory()->create([
            'name' => 'Owner Boss', 'role' => 'super_admin',
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
        ]);
        $this->agent = User::factory()->create([
            'name' => 'Elize Reichel', 'role' => 'agent',
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
        ]);
        $this->otherAgent = User::factory()->create([
            'name' => 'Kym Pollard', 'role' => 'agent',
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
        ]);
    }

    public function test_preview_auto_matches_and_flags_unmatched(): void
    {
        $this->actingAs($this->owner);
        $path = $this->buildSpreadsheet();

        $rows = app(SoldPropertyImporter::class)->preview($path, $this->agency->id);
        @unlink($path);

        $this->assertCount(2, $rows);

        // Row 2 — agent named "Elize Reichel" auto-matches.
        $this->assertSame(2, $rows[0]['row']);
        $this->assertSame($this->agent->id, $rows[0]['matched_agent_id']);
        $this->assertSame('Uvongo Beach', $rows[0]['suburb']);
        $this->assertSame(1245000, $rows[0]['price']);
        $this->assertTrue($rows[0]['has_image']);

        // Row 3 — agent "Nobody McMissing" does not match.
        $this->assertSame(3, $rows[1]['row']);
        $this->assertNull($rows[1]['matched_agent_id']);
        $this->assertFalse($rows[1]['has_image']);
    }

    public function test_import_creates_sold_properties_with_assigned_agent_and_image(): void
    {
        Storage::fake('public');
        $this->actingAs($this->owner);
        $path = $this->buildSpreadsheet();

        // Row 2 auto-matches Elize; row 3 (unmatched) is assigned Kym on review.
        $result = app(SoldPropertyImporter::class)->import($path, $this->owner, [
            3 => $this->otherAgent->id,
        ]);
        @unlink($path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertEmpty($result['issues']);

        // Auto-matched property
        $p = Property::where('price', 1245000)->first();
        $this->assertNotNull($p);
        $this->assertSame('sold', $p->status);
        $this->assertSame($this->agent->id, $p->agent_id);
        $this->assertSame($this->agency->id, $p->agency_id);
        $this->assertSame('Uvongo Beach', $p->suburb);
        $this->assertSame('Margate', $p->city);
        $this->assertSame('KwaZulu Natal', $p->province);
        $this->assertSame('Winston road', $p->street_name);
        $this->assertSame(3, $p->beds);
        $this->assertSame('Open', $p->mandate_type);
        $this->assertNotEmpty($p->images_json);

        // external_id must be an auto-generated UUID, NOT the sheet reference code.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-/', (string) $p->external_id);
        $this->assertSame('1541913', $p->p24_listing_number);

        $relative = str_replace('/storage/', '', parse_url($p->images_json[0], PHP_URL_PATH));
        Storage::disk('public')->assertExists($relative);

        $this->assertDatabaseHas('property_sold_records', [
            'property_id' => $p->id, 'sold_price' => 1245000, 'source' => 'manual',
        ]);

        // Manually-assigned property
        $ghost = Property::where('price', 500000)->first();
        $this->assertNotNull($ghost);
        $this->assertSame('sold', $ghost->status);
        $this->assertSame($this->otherAgent->id, $ghost->agent_id);
    }

    public function test_reimport_matches_existing_on_p24_code_instead_of_duplicating(): void
    {
        Storage::fake('public');
        $this->actingAs($this->owner);

        $path1 = $this->buildSpreadsheet();
        app(SoldPropertyImporter::class)->import($path1, $this->owner, [3 => $this->otherAgent->id]);
        @unlink($path1);
        $this->assertSame(2, Property::count());

        // Re-import the same file → must update existing, not duplicate.
        $path2 = $this->buildSpreadsheet();
        $result = app(SoldPropertyImporter::class)->import($path2, $this->owner, [3 => $this->otherAgent->id]);
        @unlink($path2);

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(2, Property::count(), 'Re-import must not create duplicate properties.');
    }

    public function test_non_owner_cannot_access_import_route(): void
    {
        $this->actingAs($this->agent)
            ->get(route('corex.properties.import-sold'))
            ->assertForbidden();
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray(
            ['Primary Photo', 'Address', 'Category', 'Type', 'Status', 'Status Type', 'Price',
             'Region', 'Mandate', 'Bed', 'Bath', 'Garage', 'Floor Size', 'Erf Size', 'Rates',
             'Levy', 'Keywords', 'Tags', 'Reference Code', 'Code', 'Listed', 'Modified',
             'Expire', 'Agents'],
            null, 'A1'
        );

        $rows = [
            ['addr' => "16 Winston road,\nUvongo Beach,\nMargate,\nKwaZulu Natal", 'price' => '1,245,000',
             'region' => 'Uvongo Beach, Margate, KwaZulu Natal', 'mandate' => 'Open',
             'bed' => '3', 'agents' => 'Elize Reichel', 'code' => '1541913', 'image' => true],
            ['addr' => "99 Nowhere Street,\nGhost Town,\nKwaZulu Natal", 'price' => '500,000',
             'region' => 'Ghost Town, KwaZulu Natal', 'mandate' => 'Sole',
             'bed' => '2', 'agents' => 'Nobody McMissing', 'code' => '1525599', 'image' => false],
        ];

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue("B{$r}", $row['addr']);
            $sheet->setCellValue("C{$r}", 'Residential');
            $sheet->setCellValue("D{$r}", 'House');
            $sheet->setCellValue("E{$r}", 'Sold');
            $sheet->setCellValue("F{$r}", 'Sales');
            $sheet->setCellValueExplicit("G{$r}", $row['price'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("H{$r}", $row['region']);
            $sheet->setCellValue("I{$r}", $row['mandate']);
            $sheet->setCellValue("J{$r}", $row['bed']);
            $sheet->setCellValueExplicit("T{$r}", $row['code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("X{$r}", $row['agents']);

            if ($row['image']) {
                $img = imagecreatetruecolor(40, 30);
                imagefill($img, 0, 0, imagecolorallocate($img, 100, 150, 200));
                $tmp = tempnam(sys_get_temp_dir(), 'soldimg') . '.jpg';
                imagejpeg($img, $tmp);
                imagedestroy($img);

                $drawing = new Drawing();
                $drawing->setPath($tmp);
                $drawing->setCoordinates("A{$r}");
                $drawing->setWorksheet($sheet);
            }
            $r++;
        }

        $out = tempnam(sys_get_temp_dir(), 'soldxlsx') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($out);
        return $out;
    }
}
