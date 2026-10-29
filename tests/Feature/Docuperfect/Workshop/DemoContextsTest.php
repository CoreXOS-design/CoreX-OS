<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Workshop;

use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Demo\Contexts\DemoContextResolver;
use App\Services\Docuperfect\Demo\TemplateDemoSample;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/esign-template-demo-preview.md §3a — one demo-context provider per kind of document: which provider a template gets, what each
 * one supplies, and that the demo of a sales and of a rental document shows the right people and values (no "Sample: ..." for a seller's name).
 */
final class DemoContextsTest extends TestCase
{
    use RefreshDatabase;
    use WorkshopFixtures;

    /** @return array<string,array{0:string,1:?string,2:string,3:string,4:string}> name, document type slug, category, template_type, expected key */
    public static function kinds(): array
    {
        return [
            'sole mandate to sell' => ['Exclusive Authority To Sell', 'mandate', 'sales', 'cds', 'sales_mandate'],
            'open mandate to sell' => ['Open Authority To Sell (V9)', 'mandate', '', 'sales', 'sales_mandate'],
            'offer to purchase' => ['HFC OTP (V13)', 'otp', '', 'sales', 'sale_agreement'],
            'contract of sale' => ['Contract of Sale - Estate', 'sale_agreement', '', 'sales', 'sale_agreement'],
            'seller disclosure' => ['Sales Mandatory Disclosure', 'disclosure', 'sales', 'cds', 'sales_disclosure'],
            'disclosure with no category' => ['Mandatory Disclosure', 'disclosure', '', 'cds', 'sales_disclosure'],
            'sales addendum' => ['Addendum B', 'addendum', 'sales', 'cds', 'sales_addendum'],
            'sales fica' => ['FICA Natural person (V8)', 'fica', 'sales', 'sales', 'fica'],
            'rental fica' => ['On behalf of Natural person (V8)', 'fica', 'rentals', 'rental', 'fica'],
            'lease agreement' => ['Mega Lease Agreement 2026', 'lease_agreement', 'rentals', 'cds', 'lease_agreement'],
            'lease renewal' => ['Lease Renewal 2027', null, 'rentals', 'cds', 'lease_agreement'],
            'rental mandate' => ['Rental Mandate', 'mandate', 'rentals', 'cds', 'rental_mandate'],
            'rental marketing permission' => ['Rental Marketing Permission', 'marketing_permission', 'rentals', 'cds', 'rental_mandate'],
            'rental defect disclosure' => ['Mega Defect Disclosure 2026', 'disclosure', 'rentals', 'cds', 'rental_disclosure'],
            'rental application' => ['RENTAL APPLICATION (V8)', 'rental_agreement', 'rentals', 'rental', 'rental_application'],
            'a rental notice' => ['Notice of breach', 'notice', 'rentals', 'cds', 'notice'],
            'inspection report' => ['Move-in inspection report', 'inspection_report', 'rentals', 'cds', 'inspection'],
            'inventory list' => ['Inventory list', 'inventory_list', 'rentals', 'cds', 'inspection'],
            'an HR or other document' => ['Staff acknowledgement', 'other', '', 'standard', 'generic'],
            'no type, no category, no clue' => ['Maggie', null, '', 'standard', 'generic'],
        ];
    }

    /** @dataProvider kinds */
    public function test_each_kind_of_document_gets_its_own_provider(string $name, ?string $slug, string $category, string $type, string $expected): void
    {
        $t = new Template(['name' => $name, 'category' => $category ?: null, 'template_type' => $type]);
        if ($slug !== null) {
            $t->setRelation('documentType', new DocumentType(['slug' => $slug]));
        }

        $this->assertSame($expected, app(DemoContextResolver::class)->for($t)->key());
    }

    public function test_every_provider_has_a_label_people_and_no_agency_specific_words(): void
    {
        $keys = [];
        foreach (DemoContextResolver::all() as $p) {
            $keys[] = $p->key();
            $this->assertNotSame('', $p->label());
            $this->assertNotSame([], $p->parties(), $p->key() . ' names its sample people');
            foreach ($p->parties() as $role => $party) {
                $this->assertNotSame('', trim($party['name']), "{$p->key()} / $role has a sample name");
            }
            $blob = json_encode([$p->vars(), $p->patterns(), $p->parties()]);
            foreach (['HFC', 'Home Finders', 'Mega', 'Shelly'] as $banned) {
                $this->assertStringNotContainsStringIgnoringCase($banned, $blob, $p->key() . ' is neutral for any agency');
            }
        }
        $this->assertSame($keys, array_values(array_unique($keys)), 'one provider per key');
        $this->assertCount(12, $keys);
    }

    public function test_the_document_context_follows_the_kind_not_a_fixed_rental(): void
    {
        $sales = new Template(['name' => 'Sole mandate', 'category' => 'sales', 'template_type' => 'cds']);
        $rental = new Template(['name' => 'Lease', 'category' => 'rentals', 'template_type' => 'cds']);
        $r = app(DemoContextResolver::class);

        $this->assertSame('sales', $r->for($sales)->documentContext($sales));
        $this->assertSame('rental', $r->for($rental)->documentContext($rental));
    }

    public function test_a_sales_blank_named_for_the_seller_is_filled_not_a_placeholder(): void
    {
        $a = $this->agency('Cape Agency');
        $t = $this->megaShaped($a, $this->owner(), [
            'name' => 'Exclusive Authority To Sell', 'category' => 'sales',
            'document_type_id' => $this->documentType('mandate')->id,
        ]);
        $editor = $t->editor_state;
        $editor['tags'][] = ['id' => 'tag-sel', 'type' => 'input', 'label' => '[INPUT 9]', 'number' => 9];
        $editor['mappings']['tag-sel'] = ['label' => 'Seller full', 'typeKey' => 'sf:manual', 'mappingType' => 'manual', 'namedFieldId' => null, 'fieldGroupId' => null, 'manualLabel' => 'Seller full'];
        $t->update(['editor_state' => $editor]);
        $t = $t->fresh();

        $built = app(TemplateDemoSample::class)->build($t, app(TemplateReadinessReport::class)->context($t));
        $row = collect($built['fields'])->firstWhere('label', 'Seller full');

        $this->assertSame('sales_mandate', $built['context']['key']);
        $this->assertSame('Thandi Mokoena', $row['value'], 'the sales provider knows the seller\'s name');
        $this->assertSame(TemplateDemoSample::KNOWN, $row['source']);
    }

    public function test_the_demo_screen_names_the_context_and_the_sample_people(): void
    {
        $a = $this->agency('Cape Agency');
        $owner = $this->owner();
        $t = $this->compiled($this->megaShaped($a, $owner, ['document_type_id' => $this->documentType('lease_agreement', 'Lease Agreement')->id]));

        $html = $this->actingAs($owner)->get(route('docuperfect.templates.demo', $t->id))->assertOk()->getContent();

        $this->assertStringContainsString('data-demo-context="lease_agreement"', $html);
        $this->assertStringContainsString('Lease agreement or lease renewal', $html);
        $this->assertStringContainsString('Sample people:', $html);
    }

    public function test_a_page_only_template_is_not_ready_because_it_cannot_be_moved(): void
    {
        $a = $this->agency('Cape Agency');
        $t = $this->megaShaped($a, $this->owner(), [], ['tagged_html' => ''], ['sections' => []]);

        $report = app(TemplateReadinessReport::class)->forTemplate($t->fresh());
        $r01 = collect($report['checks'])->firstWhere('id', 'R01');

        $this->assertSame('fail', $r01['status']);
        $this->assertStringContainsString('cannot be moved to an agency', $r01['detail']);
        $this->assertSame('not_ready', $report['verdict']);
    }

    public function test_the_agency_sees_documents_from_corex_with_a_demo_button_and_state_counts(): void
    {
        $workshop = $this->agency('CoreX Workshop');
        $client = $this->agency('Cape Agency');
        $owner = $this->owner();
        $source = $this->megaShaped($workshop, $owner);
        [$release, $copy] = $this->releasedCopy($source, $client, $owner);
        $admin = $this->agencyAdmin($client);

        $html = $this->actingAs($admin)->get(route('docuperfect.template-approvals.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Documents from CoreX', $html);
        $this->assertStringContainsString('data-agency-workshop-how', $html);
        $this->assertStringContainsString('data-state-chip="awaiting_approval"', $html);
        $this->assertMatchesRegularExpression('/data-state-chip="awaiting_approval">[^<]*: 1</', $html);
        $this->assertStringContainsString('data-link-authoriser-setting', $html);
        $this->assertStringContainsString(route('docuperfect.templates.demo', $copy->id), $html, 'Demo view of the waiting copy');
        $this->actingAs($admin)->get(route('docuperfect.templates.demo', $copy->id))->assertOk();
    }
}
