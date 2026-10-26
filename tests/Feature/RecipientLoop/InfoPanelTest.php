<?php

declare(strict_types=1);

namespace Tests\Feature\RecipientLoop;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B3 — Left info panel on the recipient signing view. Renders five-step
 * guide, dispatching agent's contact card, and "Signing as" block.
 */
final class InfoPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_info_panel_renders_for_recipient_signing_view(): void
    {
        [$sigTmpl, $seller2] = $this->seedTwoSellerTemplate();

        $resp = $this->get('/sign/' . $seller2->token);
        $resp->assertOk();
        $resp->assertSee('recipient-info-panel', false);
        $resp->assertSee('Your three steps', false);
    }

    public function test_signing_as_label_uses_indexed_role(): void
    {
        [$sigTmpl, $seller2] = $this->seedTwoSellerTemplate();

        $resp = $this->get('/sign/' . $seller2->token);
        $resp->assertOk();
        // The B1 role identity for seller_2 is "Seller 2" via roleDisplayLabel.
        $resp->assertSee('Seller 2', false);
        $resp->assertSee('Steve Jobs', false);
    }

    public function test_single_recipient_uses_singleton_role_label(): void
    {
        [$sigTmpl, $solo] = $this->seedSoloSellerTemplate();

        $resp = $this->get('/sign/' . $solo->token);
        $resp->assertOk();
        // Single seller → "Seller" (no index) per roleDisplayLabel.
        $resp->assertSee('Solo Seller', false);
        $resp->assertDontSee('Seller 1');
        $resp->assertDontSee('Seller 2');
    }

    public function test_agent_contact_card_renders_from_template_creator(): void
    {
        [$sigTmpl, $seller2, $agentEmail] = $this->seedTwoSellerTemplateWithAgentDetails();

        $resp = $this->get('/sign/' . $seller2->token);
        $resp->assertOk();
        // First-time signer flow (esign-signing-modes.md §13.1): help is ONE line - "Questions? Call <agent> <phone>".
        $resp->assertSee('Questions? Call', false);
        $resp->assertSee('Lewis Listing', false);
        $resp->assertSee('+27 76 618 5578', false);
        $resp->assertDontSee('Need help?', false);
    }

    public function test_panel_uses_responsive_classes(): void
    {
        // The CSS class is the regression handle for the @media gate.
        // If the class name changes accidentally the desktop layout breaks.
        [$sigTmpl, $seller2] = $this->seedTwoSellerTemplate();

        $resp = $this->get('/sign/' . $seller2->token);
        $resp->assertOk();
        $resp->assertSee('recipient-info-panel__inner', false);
        $resp->assertSee('has-recipient-info-panel', false);
        $resp->assertSee('recipient-info-main', false);
    }

    // ── Helpers ──

    /**
     * @return array{0: SignatureTemplate, 1: SignatureRequest} (template, seller2)
     */
    private function seedTwoSellerTemplate(): array
    {
        [$sigTmpl, $reqs] = $this->seedTemplate([
            ['party_role' => 'seller', 'name' => 'James Vdm',  'role_index' => 1],
            ['party_role' => 'seller', 'name' => 'Steve Jobs', 'role_index' => 2],
            ['party_role' => 'agent',  'name' => 'Lewis Listing', 'role_index' => 1],
        ]);
        return [$sigTmpl, $reqs[1]]; // seller_2
    }

    /**
     * @return array{0: SignatureTemplate, 1: SignatureRequest, 2: string} (template, seller2, agentEmail)
     */
    private function seedTwoSellerTemplateWithAgentDetails(): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name'  => 'Lewis Listing',
            'email' => 'lewis.listing-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'),
            'role'  => 'agent',
            'phone' => '+27 76 618 5578',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $email = (string) DB::table('users')->where('id', $userId)->value('email');
        [$sigTmpl, $sellers] = $this->buildTemplate($userId, [
            ['party_role' => 'seller', 'name' => 'James Vdm', 'role_index' => 1],
            ['party_role' => 'seller', 'name' => 'Steve Jobs', 'role_index' => 2],
        ]);
        return [$sigTmpl, $sellers[1], $email];
    }

    /**
     * @return array{0: SignatureTemplate, 1: SignatureRequest}
     */
    private function seedSoloSellerTemplate(): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name'  => 'Listing Agent',
            'email' => 'la-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'),
            'role'  => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        [$sigTmpl, $sellers] = $this->buildTemplate($userId, [
            ['party_role' => 'seller', 'name' => 'Solo Seller', 'role_index' => 1],
        ]);
        return [$sigTmpl, $sellers[0]];
    }

    /**
     * @param  list<array{party_role:string,name:string,role_index:int}> $rows
     * @return array{0: SignatureTemplate, 1: list<SignatureRequest>}
     */
    private function seedTemplate(array $rows): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name'  => 'Default Agent',
            'email' => 'a-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'),
            'role'  => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->buildTemplate($userId, $rows);
    }

    /**
     * @param  list<array{party_role:string,name:string,role_index:int}> $rows
     * @return array{0: SignatureTemplate, 1: list<SignatureRequest>}
     */
    private function buildTemplate(int $userId, array $rows): array
    {
        $docTmpl = DocuperfectTemplate::create([
            'name'           => 'Panel test',
            'render_type'    => 'web',
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> ['owner_party', 'agent'],
            'field_mappings' => [],
            'owner_id'       => $userId,
        ]);
        $doc = Document::create([
            'name'         => 'Panel doc',
            'document_type'=> 'agreement',
            'owner_id'     => $userId,
            'template_id'  => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div>placeholder body</div>'],
        ]);
        $sigTmpl = SignatureTemplate::create([
            'document_id'   => $doc->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_SIGNING,
            'created_by'    => $userId,
        ]);
        $reqs = [];
        foreach ($rows as $r) {
            $reqs[] = SignatureRequest::create([
                'signature_template_id' => $sigTmpl->id,
                'party_role'  => $r['party_role'],
                'role_index'  => $r['role_index'],
                'signer_name' => $r['name'],
                'signer_email'=> strtolower(str_replace(' ', '.', $r['name'])) . '@x.test',
                'token'       => Str::random(48),
                'token_expires_at' => now()->addDays(30),
                'status'      => 'pending',
                'signing_order' => $r['role_index'],
            ]);
        }
        return [$sigTmpl, $reqs];
    }
}
