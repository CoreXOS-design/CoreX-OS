<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\User;
use App\Services\Rentals\RentalInspectionFormPdfService;
use App\Services\Rentals\RentalInspectionReportPdfService;
use App\Services\Rentals\RentalInventoryReportPdfService;
use App\Support\Documents\AgencyLetterhead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mega gap (27 Oct 2026): every document CoreX produces for an agency carries the agency's letterhead - logo left; name, address, tel,
 * e-mail, website (and VAT) right; footer "<legal name> | Reg No <reg> | PPRA Reg <ppra>" - from the agency's settings, through ONE shared
 * component. Proven on the four documents (inventory report, completed inspection report, blank inspection form, FICA form), and that a
 * blank setting prints no line at all (never "VAT No:" with nothing after it). Spec: .ai/specs/agency-letterhead.md
 */
final class AgencyLetterheadTest extends TestCase
{
    use RefreshDatabase;

    private const FOOTER = 'VIPS cc t/a Mega Rent Real Estate | Reg No CK2003/088713/23 | PPRA Reg 1135017';

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');
        Storage::fake('public');

        $this->agency = Agency::create([
            'name' => 'Mega Real Estate', 'slug' => 'mega-' . uniqid(), 'trading_name' => 'Mega Real Estate',
            'legal_name' => 'VIPS cc t/a Mega Rent Real Estate', 'reg_no' => 'CK2003/088713/23', 'ppra_number' => '1135017',
            'vat_no' => '4123456789', 'address' => "20 Madeira Crescent\nTable View, 7441", 'phone' => '0845576594',
            'email' => 'info@megarent.co.za', 'document_website' => 'www.megarent.co.za',
            // The public-listing redirect setting: must never appear on a document.
            'website_url' => 'https://redirect-only.example.test',
        ]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '9 Letterhead Lane', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonth(), 'created_by_user_id' => $this->agent->id,
        ]);
        $this->actingAs($this->agent);
    }

    private function room(): PropertyRoom
    {
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => 'Bedroom 1',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 1 walls', 'space_type' => 'Bedroom',
            'source' => 'manual', 'sort_order' => 1, 'created_by_user_id' => $this->agent->id,
        ]);

        return $room;
    }

    /** @return array<string, string> document name => rendered HTML */
    private function documents(): array
    {
        $room = $this->room();

        $inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id, 'property_room_id' => $room->id,
            'room_label' => 'Bedroom 1', 'quantity' => 1, 'description' => 'Bed', 'created_by_user_id' => $this->agent->id,
        ]);
        $inspection = RentalInspection::start($this->property, RentalInspection::TYPE_IN, $this->agent);

        // The blank form is a DomPDF render of a positioned layout: capture the HTML it is built from.
        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);
        $captured = new class(app('dompdf'), app('config'), app('files'), app('view')) extends \Barryvdh\DomPDF\PDF {
            public static string $html = '';

            public function loadHTML(string $string, ?string $encoding = null): \Barryvdh\DomPDF\PDF
            {
                self::$html = $string;

                return parent::loadHTML($string, $encoding);
            }
        };
        $this->app->bind('dompdf.wrapper', fn () => new (get_class($captured))(app('dompdf'), app('config'), app('files'), app('view')));
        app(RentalInspectionFormPdfService::class)->render($form, $inspection);
        $formHtml = (get_class($captured))::$html;

        $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Ngcobo']);
        $submission = FicaSubmission::create([
            'contact_id' => $contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'requested_by' => $this->agent->id,
            'status' => 'draft', 'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
        \Illuminate\Support\Facades\Auth::logout(); // the public FICA page is opened by a tenant, nobody signed in

        return [
            'inventory report' => app(RentalInventoryReportPdfService::class)->generate($inventory->fresh())->getDomPDF()->outputHtml(),
            'inspection report' => app(RentalInspectionReportPdfService::class)->generate($inspection->fresh())->getDomPDF()->outputHtml(),
            'blank inspection form' => $formHtml,
            'FICA form' => $this->get(route('fica.form', $submission->token))->assertOk()->getContent(),
        ];
    }

    public function test_the_letterhead_renders_on_each_of_the_four_documents(): void
    {
        foreach ($this->documents() as $name => $html) {
            $this->assertStringContainsString('Table View, 7441', $html, "$name: address");
            $this->assertStringContainsString('Tel: 0845576594', $html, "$name: tel");
            $this->assertStringContainsString('info@megarent.co.za', $html, "$name: e-mail");
            $this->assertStringContainsString('www.megarent.co.za', $html, "$name: website");
            $this->assertStringContainsString('VAT No: 4123456789', $html, "$name: VAT line");
            $this->assertStringContainsString(self::FOOTER, $html, "$name: footer line");
            $this->assertStringNotContainsString('redirect-only.example.test', $html, "$name: the listing-redirect website never reaches a document");
        }
    }

    public function test_a_blank_vat_no_prints_no_vat_line_on_any_document(): void
    {
        $this->agency->update(['vat_no' => null]);

        foreach ($this->documents() as $name => $html) {
            $this->assertStringNotContainsString('VAT No:', $html, "$name: no VAT line");
            $this->assertStringContainsString(self::FOOTER, $html, "$name: the rest of the letterhead is still there");
        }
    }

    public function test_blank_settings_leave_their_lines_out_and_the_footer_falls_back_to_the_trading_name(): void
    {
        $this->agency->update(['legal_name' => null, 'reg_no' => null, 'ppra_number' => null, 'document_website' => null, 'email' => null]);

        $lh = AgencyLetterhead::for($this->agency->fresh());

        $this->assertSame('Mega Real Estate', $lh->footerLine, 'no reg / PPRA = no empty "Reg No" parts');
        $this->assertSame('', $lh->website);
        $this->assertSame('', $lh->email);

        $html = view('components.agency-letterhead', ['letterhead' => $lh])->render();
        $this->assertStringNotContainsString('Reg No', $html);
        $this->assertStringNotContainsString('www.', $html);
    }

    public function test_the_footer_is_the_agencys_own_and_never_another_agencys(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid(), 'legal_name' => 'Other (Pty) Ltd', 'reg_no' => '2000/000001/07']);
        // withoutEvents: the agency trait would otherwise stamp the signed-in user's agency on the branch.
        $foreignBranch = Branch::withoutEvents(fn () => Branch::forceCreate(['name' => 'Elsewhere', 'agency_id' => $other->id, 'trading_name' => 'Intruder Properties']));

        $lh = AgencyLetterhead::for($this->agency, $foreignBranch);

        $this->assertSame('Mega Real Estate', $lh->name);
        $this->assertStringStartsWith('VIPS cc t/a Mega Rent Real Estate', $lh->footerLine);
    }

    public function test_the_company_settings_form_saves_the_two_new_fields_and_ignores_a_post_that_does_not_carry_them(): void
    {
        $this->actingAs($this->agent)->put(route('admin.company-settings.update', $this->agency), [
            'legal_name' => 'New Legal cc', 'document_website' => 'www.new.test', 'reg_no' => 'X1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('New Legal cc', $this->agency->fresh()->legal_name);
        $this->assertSame('www.new.test', $this->agency->fresh()->document_website);

        $this->put(route('admin.company-settings.update', $this->agency), ['reg_no' => 'X2'])->assertSessionHasNoErrors();
        $this->assertSame('New Legal cc', $this->agency->fresh()->legal_name, 'a post without the boxes never empties them');
    }
}
