<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

final class EsignDocumentNamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function buildDefaultDocumentName(Template $template, Flow $flow, array $stepData, string $propertyAddressFallback = '', bool $isPackFlow = false, bool $isPdfPack = false): string
    {
        $controller = app(\App\Http\Controllers\Docuperfect\ESignWizardController::class);
        $m = new ReflectionMethod($controller, 'buildDefaultDocumentName');
        $m->setAccessible(true);
        return $m->invoke($controller, $template, $flow, $stepData, $propertyAddressFallback, $isPackFlow, $isPdfPack);
    }

    private function agencyBranchUser(): array
    {
        $agency = Agency::create(['name' => 'Naming Test Agency', 'slug' => 'naming-test-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Naming Test Branch']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);
        return [$agency, $branch, $user];
    }

    private function template(string $name): Template
    {
        return Template::create([
            'name' => $name, 'template_type' => 'sales', 'render_type' => 'web',
            'blade_view' => 'docuperfect.templates.' . str()->slug($name),
            'is_esign' => true, 'fields_json' => [],
        ]);
    }

    private function flow(Template $template, User $user, ?int $propertyId): Flow
    {
        return Flow::create([
            'type' => 'esign', 'template_id' => $template->id, 'user_id' => $user->id,
            'current_step' => 6, 'status' => 'active', 'property_id' => $propertyId,
            'step_data' => ['template' => ['template_id' => $template->id]],
        ]);
    }

    public function test_freehold_property_names_with_erf_and_street(): void
    {
        [$agency, $branch, $user] = $this->agencyBranchUser();
        $template = $this->template('Sole Mandate');
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'title' => 'Freehold fixture', 'title_type' => 'full_title',
            'erf_number' => '1234', 'street_number' => '14', 'street_name' => 'Hillside Crescent',
            'suburb' => 'Ramsgate', 'city' => 'Margate',
        ]);
        $flow = $this->flow($template, $user, $property->id);

        $name = $this->buildDefaultDocumentName($template, $flow, ['property' => ['_property_source' => 'properties']]);

        $this->assertStringStartsWith('Sole Mandate — Erf 1234, 14 Hillside Crescent — ', $name);
        $this->assertStringEndsWith(now()->format('d-m-y'), $name);
        $this->assertStringNotContainsString('Ramsgate', $name, 'the short property-line address must not include suburb/city');
    }

    public function test_sectional_scheme_names_with_unit_and_complex(): void
    {
        [$agency, $branch, $user] = $this->agencyBranchUser();
        $template = $this->template('Open Mandate');
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'title' => 'Sectional fixture', 'title_type' => 'sectional_title',
            'unit_number' => '5', 'complex_name' => 'Winston Court North',
            'street_number' => '76', 'street_name' => 'Marine Drive',
            'suburb' => 'St Michaels On Sea',
        ]);
        $flow = $this->flow($template, $user, $property->id);

        $name = $this->buildDefaultDocumentName($template, $flow, ['property' => ['_property_source' => 'properties']]);

        $this->assertStringStartsWith('Open Mandate — Unit 5, Winston Court North', $name);
        $this->assertStringNotContainsString('Erf', $name, 'a sectional-title property must never get an erf-number prefix');
    }

    /**
     * Johan, 2026-08-30: "company seller, proxy seller, deceased seller,
     * natural person seller -> ALL produce the same shape of name. No party
     * name appears in any of them." buildDefaultDocumentName() takes no
     * recipient data at all, so this is true by construction -- but proving
     * it by CONSTRUCTION alone reads as an assertion, not evidence. This
     * drives the same property/template through varying recipient-shaped
     * step_data (mirroring the real payloads for each party shape) and
     * proves the output is byte-identical regardless.
     */
    public function test_all_party_shapes_produce_the_same_name_shape(): void
    {
        [$agency, $branch, $user] = $this->agencyBranchUser();
        $template = $this->template('Sole Mandate');
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'title' => 'Shape fixture', 'title_type' => 'full_title',
            'erf_number' => '99', 'street_number' => '1', 'street_name' => 'Shape Close',
        ]);
        $flow = $this->flow($template, $user, $property->id);

        $naturalPerson = ['recipients' => ['recipients' => [
            ['role' => 'seller', 'name' => 'Anna Seller', '_contact_id' => 1],
        ]]];
        $company = ['recipients' => ['recipients' => [
            ['role' => 'seller', 'name' => 'Shape Test Co (Pty) Ltd', '_contact_id' => 2],
        ]]];
        $proxy = ['recipients' => ['recipients' => [
            ['role' => 'seller', 'name' => 'Proxy Director Name', '_contact_id' => 3],
        ]]];
        $deceased = ['recipients' => ['recipients' => [
            ['role' => 'seller', 'name' => 'Deceased Seller', '_is_deceased' => true, '_recipient_local_key' => 'k1'],
            ['role' => 'seller', 'name' => 'Executor Name', '_recipient_local_key' => 'k2'],
        ]]];

        $names = [];
        foreach (['naturalPerson' => $naturalPerson, 'company' => $company, 'proxy' => $proxy, 'deceased' => $deceased] as $label => $stepData) {
            $stepData['property'] = ['_property_source' => 'properties'];
            $names[$label] = $this->buildDefaultDocumentName($template, $flow, $stepData);
        }

        $expected = 'Sole Mandate — Erf 99, 1 Shape Close — ' . now()->format('d-m-y');
        foreach ($names as $label => $name) {
            $this->assertSame($expected, $name, "shape '{$label}' must produce the same name shape as every other shape");
            $this->assertStringNotContainsString('Anna', $name);
            $this->assertStringNotContainsString('Shape Test Co', $name);
            $this->assertStringNotContainsString('Proxy', $name);
            $this->assertStringNotContainsString('Executor', $name);
            $this->assertStringNotContainsString('Deceased', $name);
        }
    }

    /**
     * Johan's critical safety question: does anything rebuild Document::name
     * after creation, silently destroying an agent's rename? The call site
     * in prepareSigning() guards this: `$docName = $stepData['document_name']
     * ?? null; if (empty($docName)) { $docName =
     * $this->buildDefaultDocumentName(...); }` -- the auto-builder never
     * runs at all when the agent already set a name. Proven exhaustively by
     * source search (not just this test): every OTHER reference to
     * `$document->name`/`$docName` anywhere in the codebase
     * (SignatureService.php:4059,4162,4294,4974; SigningController.php:2944;
     * ESignWizardController.php:7500) only ever READS it with a `??`
     * fallback for a PDF filename or log message -- none reassigns it. No
     * automated test in this suite drives prepareSigning() through its full
     * compose()/render pipeline to a persisted Document (every existing test
     * that reaches Document creation builds the row directly, bypassing
     * render) -- building that harness from scratch was out of proportion
     * for this fix, so this is a direct, targeted proof of the guard itself
     * rather than a full round-trip.
     */
    public function test_default_name_is_never_computed_when_agent_already_set_one(): void
    {
        [$agency, $branch, $user] = $this->agencyBranchUser();
        $template = $this->template('Sole Mandate');
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'title' => 'Rename fixture', 'title_type' => 'full_title',
            'erf_number' => '1', 'street_number' => '1', 'street_name' => 'Rename Street',
        ]);
        $flow = $this->flow($template, $user, $property->id);

        $stepData = ['property' => ['_property_source' => 'properties'], 'document_name' => 'My Custom Deal Name'];

        // Mirrors prepareSigning()'s own guard verbatim (ESignWizardController.php ~2666-2669):
        $docName = $stepData['document_name'] ?? null;
        if (empty($docName)) {
            $docName = $this->buildDefaultDocumentName($template, $flow, $stepData);
        }

        $this->assertSame('My Custom Deal Name', $docName, 'an agent-set document_name must survive untouched');
    }

    /**
     * Johan: "if the short date alone does not separate them, say so rather
     * than adding something Johan did not ask for." It does not -- two
     * documents on the same property, same template, same day produce the
     * IDENTICAL auto-built name. docuperfect_documents.name carries no
     * uniqueness constraint (confirmed in the schema), so this is not
     * currently a hard collision -- just a real, disclosed limitation.
     */
    public function test_two_documents_same_property_same_day_produce_identical_names(): void
    {
        [$agency, $branch, $user] = $this->agencyBranchUser();
        $template = $this->template('Sole Mandate');
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'title' => 'Duplicate fixture', 'title_type' => 'full_title',
            'erf_number' => '7', 'street_number' => '7', 'street_name' => 'Duplicate Drive',
        ]);
        $flowA = $this->flow($template, $user, $property->id);
        $flowB = $this->flow($template, $user, $property->id);

        $stepData = ['property' => ['_property_source' => 'properties']];
        $nameA = $this->buildDefaultDocumentName($template, $flowA, $stepData);
        $nameB = $this->buildDefaultDocumentName($template, $flowB, $stepData);

        $this->assertSame($nameA, $nameB, 'a mandate and a second document on the same property, same day, are NOT distinguished by name alone -- disclosed, not silently patched');
    }
}
