<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\WebTemplateBladeEnsurer;
use App\Services\WebTemplateDataService;
use Tests\Feature\Docuperfect\PaperRouteFixtureCase;

/**
 * AT-498 — three parties, A holds a proxy: partial (A signs for B only), full (A signs for everyone), none. On every route
 * (electronic, download-and-sign, wet-ink), single documents and a pack. The fixture templates carry a seller domicilium
 * block and the shared signature-block component, exactly the pieces the rule decides.
 */
final class PartialProxyRoutesTest extends PaperRouteFixtureCase
{
    private const A = 'Anne Adams';
    private const B = 'Ben Brown';
    private const C = 'Carl Clark';

    /** @return list<array<string, mixed>> */
    private function parties(string $mode, string $role = 'seller', int $count = 3): array
    {
        $names = [self::A, self::B, self::C, 'Dora Dunn', 'Eli Evans'];
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'role' => $role, 'order' => $i + 1, 'name' => $names[$i], 'first_name' => explode(' ', $names[$i])[0], 'last_name' => explode(' ', $names[$i])[1],
                'email' => "p{$i}@example.test", 'cell' => '08200000' . $i . $i, 'address' => ($i + 1) . ' Domicilium Road',
                'id_number' => '800101500908' . $i, 'signing_order' => $i + 1, '_recipient_local_key' => 'key-' . $i,
            ];
        }
        if ($mode === 'partial') {
            // A signs for everyone except the LAST party.
            $rows[0]['_is_proxy'] = true;
            $rows[0]['_proxy_excludes'] = ['key-' . ($count - 1)];
        } elseif ($mode === 'full') {
            $rows[0]['_is_proxy'] = true;
        }

        return $rows;
    }

    /** @param list<Template> $templates */
    private function flowOf(array $templates, array $recipients, bool $pack): Flow
    {
        $flow = $this->flow($templates, 2, $pack);
        $step = $flow->step_data;
        $step['recipients'] = ['recipients' => $recipients];
        $flow->update(['step_data' => $step]);

        return $flow->fresh();
    }

    private function document(Flow $flow): Document
    {
        $id = $flow->fresh()->step_data['document_id'];

        return Document::findOrFail($id);
    }

    /** @return array<string, SignatureRequest> */
    private function requestsByName(Document $d): array
    {
        $out = [];
        foreach ($d->signatureTemplate->requests()->where('party_role', '!=', 'agent')->get() as $r) {
            $out[$r->signer_name] = $r;
        }

        return $out;
    }

    private function signingNames(Document $d): array
    {
        $names = [];
        foreach ($this->requestsByName($d) as $name => $r) {
            if ($r->isSigningParticipant()) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }

    private function printedOf(Document $d): string
    {
        $canonical = trim((string) ($d->web_template_data['canonical_html'] ?? ''));

        return $canonical !== '' ? $canonical : (string) ($d->web_template_data['merged_html'] ?? '');
    }

    /** The names listed in the "Thus done and signed by the Seller…" lines of a document. */
    private function signatureBlockText(string $html): string
    {
        preg_match_all('/Thus done and signed by the Seller[^@]{0,600}?\bat\b/s', $html, $m);

        return implode(' || ', $m[0]);
    }

    // ---- wording (resolver) -----------------------------------------------------------------------------------

    public function test_wording_for_partial_and_full_proxy_and_none(): void
    {
        $svc = app(WebTemplateDataService::class);
        $step = fn (array $recipients) => ['property' => ['property_id' => $this->property->id, '_property_source' => 'properties'], 'recipients' => ['recipients' => $recipients]];

        $partial = $svc->resolve($this->templates[0]->id, $step($this->parties('partial')), $this->agent);
        $this->assertSame(self::A, $partial['seller_name'], 'the proxy keeps their own plain name');
        $this->assertStringContainsString('Ben Brown (ID: 8001015009081), herein represented by Anne Adams (ID: 8001015009080) in terms of a proxy', $partial['seller_name_2']);
        $this->assertSame(self::C, $partial['seller_name_3'] ?? self::C, 'C signs for themself — no representation wording');

        $full = $svc->resolve($this->templates[0]->id, $step($this->parties('full')), $this->agent);
        $this->assertStringContainsString('herein represented by Anne Adams', $full['seller_name_2']);

        $none = $svc->resolve($this->templates[0]->id, $step($this->parties('none')), $this->agent);
        $this->assertSame(self::B, $none['seller_name_2'], 'no proxy → plain names, unchanged');
    }

    // ---- download-and-sign ------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0:string,1:list<string>}>
     */
    public static function modes(): array
    {
        return [
            'partial: A for B only → A and C sign' => ['partial', [self::A, self::C]],
            'full: A for everyone → only A signs' => ['full', [self::A]],
            'none: everyone signs' => ['none', [self::A, self::B, self::C]],
        ];
    }

    /**
     * @dataProvider modes
     */
    public function test_download_and_sign_signature_blocks_and_domicilium(string $mode, array $expectedSigners): void
    {
        $flow = $this->flowOf([$this->templates[0]], $this->parties($mode), false);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $printed = $this->printedOf($this->document($flow));
        $blocks = $this->signatureBlockText($printed);

        foreach ([self::A, self::B, self::C] as $name) {
            if (in_array($name, $expectedSigners, true)) {
                $this->assertStringContainsString("({$name})", $blocks, "{$mode}: {$name} has a signature block");
            } else {
                $this->assertStringNotContainsString("({$name})", $blocks, "{$mode}: {$name} (represented) has no signature block of their own");
            }
        }
        // every party still prints their own domicilium block, signer or not
        $this->assertSame(3, substr_count($printed, 'data-field="seller_address'), "{$mode}: one domicilium block per party");
    }

    // ---- wet-ink ----------------------------------------------------------------------------------------------

    /**
     * @dataProvider modes
     */
    public function test_wet_ink_recipients_and_blocks(string $mode, array $expectedSigners): void
    {
        $flow = $this->flowOf([$this->templates[0]], $this->parties($mode), false);
        $this->runRoute('docuperfect.esign.prepareWetInk', $flow);
        $doc = $this->document($flow);

        $expected = $expectedSigners;
        sort($expected);
        $this->assertSame($expected, $this->signingNames($doc), "{$mode}: recipients who actually sign");
        $this->assertCount(3, $this->requestsByName($doc), 'never drop a party: all three have a request row');
        $blocks = $this->signatureBlockText($this->printedOf($doc));
        foreach ([self::A, self::B, self::C] as $name) {
            in_array($name, $expectedSigners, true)
                ? $this->assertStringContainsString("({$name})", $blocks)
                : $this->assertStringNotContainsString("({$name})", $blocks);
        }
        $this->assertSame(3, substr_count($this->printedOf($doc), 'data-field="seller_address'), 'one domicilium per party');
    }

    // ---- electronic -------------------------------------------------------------------------------------------

    /**
     * @dataProvider modes
     */
    public function test_electronic_recipients_and_blocks(string $mode, array $expectedSigners): void
    {
        app(WebTemplateBladeEnsurer::class)->ensure($this->templates[0]);
        $flow = $this->flowOf([$this->templates[0]], $this->parties($mode), false);

        $res = $this->actingAs($this->agent)->post(route('docuperfect.esign.prepareSigning', $flow->id), ['_json' => true], ['Accept' => 'application/json']);
        $this->assertSame(200, $res->getStatusCode(), substr($res->getContent(), 0, 400));
        $doc = Document::latest('id')->first();

        $expected = $expectedSigners;
        sort($expected);
        $this->assertSame($expected, $this->signingNames($doc), "{$mode}: electronic recipients who actually sign");
        $rows = $this->requestsByName($doc);
        $this->assertCount(3, $rows, 'never drop a party');
        if ($mode === 'partial') {
            $this->assertSame(['key-2'], $rows[self::A]->proxy_excludes, 'the exclusion list is frozen on the proxy row');
            $this->assertStringContainsString('herein represented by Anne Adams', (string) $rows[self::B]->party_clause_text);
            $this->assertEmpty($rows[self::C]->party_clause_text, 'C signs for themself');
            $this->assertSame(SignatureRequest::NON_SIGNING_REASON_PROXY_COLLAPSED, $rows[self::B]->nonSigningReason());
            $this->assertNull($rows[self::C]->nonSigningReason());
        }
        if ($mode === 'full') {
            $this->assertEmpty($rows[self::A]->proxy_excludes, 'no exclusions stored = signs for everyone (the existing meaning)');
        }
    }

    // ---- N parties, rental roles ------------------------------------------------------------------------------

    public function test_four_parties_and_rental_roles_follow_the_same_rule(): void
    {
        foreach (['seller', 'landlord'] as $role) {
            $flow = $this->flowOf([$this->templates[0]], $this->parties('partial', $role, 4), false);
            $this->runRoute('docuperfect.esign.prepareWetInk', $flow);
            $doc = $this->document($flow);

            $this->assertSame([self::A, 'Dora Dunn'], $this->signingNames($doc), "{$role}: A (for B and C) and D (for self) sign");
            $this->assertCount(4, $this->requestsByName($doc));
        }
    }

    // ---- packs ------------------------------------------------------------------------------------------------

    public function test_a_pack_applies_the_proxy_to_every_document(): void
    {
        foreach (['docuperfect.esign.prepareDownload', 'docuperfect.esign.prepareWetInk'] as $route) {
            $flow = $this->flowOf($this->templates, $this->parties('partial'), true);
            $this->runRoute($route, $flow);
            $docs = $this->packDocuments($flow);
            $this->assertCount(3, $docs);

            foreach ($docs as $d) {
                $blocks = $this->signatureBlockText($this->printedOf($d));
                $this->assertStringContainsString('(' . self::A . ')', $blocks, "{$route}: A signs in every pack document");
                $this->assertStringContainsString('(' . self::C . ')', $blocks, "{$route}: C signs in every pack document");
                $this->assertStringNotContainsString('(' . self::B . ')', $blocks, "{$route}: B never gets a block of their own");
            }
        }
    }

    // ---- guard ------------------------------------------------------------------------------------------------

    public function test_a_proxy_who_signs_for_nobody_is_refused_on_every_route(): void
    {
        $rows = $this->parties('partial');
        $rows[0]['_proxy_excludes'] = ['key-1', 'key-2'];   // un-ticked both B and C
        app(WebTemplateBladeEnsurer::class)->ensure($this->templates[0]);
        $before = Document::count();

        foreach (['docuperfect.esign.prepareDownload', 'docuperfect.esign.prepareWetInk', 'docuperfect.esign.prepareSigning'] as $route) {
            $flow = $this->flowOf([$this->templates[0]], $rows, false);
            $res = $this->actingAs($this->agent)->post(route($route, $flow->id), [], ['Accept' => 'application/json']);
            $this->assertSame(422, $res->getStatusCode(), $route);
            $this->assertStringContainsString('signs for nobody', $res->getContent());
        }
        $this->assertSame($before, Document::count(), 'nothing was created');
    }

    // ---- existing flows unchanged -----------------------------------------------------------------------------

    public function test_a_flow_with_no_proxy_and_a_flow_with_a_full_proxy_keep_their_existing_output(): void
    {
        // none: three plain parties — no wording, no caption, all three block entries.
        $flow = $this->flowOf([$this->templates[0]], $this->parties('none'), false);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $printed = $this->printedOf($this->document($flow));
        $this->assertStringNotContainsString('herein represented by', $printed);
        $this->assertStringNotContainsString('as proxy for', $printed);

        // full proxy with the OLD stored shape (no _proxy_excludes key at all) behaves as "signs for everyone"
        $legacy = $this->parties('full');
        foreach ($legacy as &$r) {
            unset($r['_proxy_excludes']);
        }
        unset($r);
        $flow = $this->flowOf([$this->templates[0]], $legacy, false);
        $this->runRoute('docuperfect.esign.prepareWetInk', $flow);
        $this->assertSame([self::A], $this->signingNames($this->document($flow)));
    }
}
