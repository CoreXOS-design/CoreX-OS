<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\SelectionEditService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-373 amendment-initial DROP regression (Johan's 7-page signed PDF, doc 705).
 *
 * With TWO amendments and THREE parties (agent + two sellers), the 1st recipient's amendment-initials
 * vanished from the completed PDF while the 2nd recipient's persisted. Root cause: recordChangeInitial
 * wrote the per-party cir-slot fill into whichever artifact amendSource picked — merged_html while the
 * doc was still v0, canonical_html once baked. completeWeb bakes the STORED canonical_html and freezes
 * it at version >= 1, so a fill recorded pre-bake (merged_html only) was dropped from the served canonical.
 *
 * The fix keeps the fill in BOTH artifacts. This test reproduces the exact v0 → bake → v>=1 sequence and
 * asserts EVERY party's initial survives on EVERY amendment in the served (canonical) document.
 */
final class AmendmentInitialPersistsAcrossBakeTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_all_parties_amendment_initials_persist_across_the_bake_boundary(): void
    {
        $sel = app(SelectionEditService::class);
        $svc = app(SignatureService::class);

        // A body with two clauses the agent will amend (clause 3 + clause 4.1).
        $body = '<div class="corex-document-wrapper">'
            . '<p class="corex-clause">Clause 3: the fee is seven percent (7%) of the price.</p>'
            . '<p class="corex-clause">Clause 4.1: occupation on the first of the month.</p>'
            . '</div>';
        $parties = [
            ['key' => 'agent',    'name' => 'Johan Reichel'],
            ['key' => 'seller',   'name' => 'Anine Van der Westhuizen'],
            ['key' => 'seller_2', 'name' => 'Andre Roets'],
        ];
        // Author TWO amendments, chaining so both change-initial rows (each with all 3 party slots) coexist.
        $a1 = $sel->applyStrikeToHtml($body, 'seven percent (7%)', 'the fee is ', ' of the price', 'six percent (6%)', 'inline', $parties);
        $this->assertNotNull($a1);
        $a2 = $sel->applyStrikeToHtml($a1['html'], 'the first of the month', 'occupation on ', '.', 'the last of the month', 'inline', $parties);
        $this->assertNotNull($a2);
        $doc0 = $a2['html'];
        $cid1 = $a1['change_id'];
        $cid2 = $a2['change_id'];

        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Johan Reichel', 'email' => 'j-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Bk tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        // Start UN-BAKED (v0): both artifacts identical, as after the agent authored the amendments.
        $doc = Document::create([
            'name' => 'Bk Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $doc0, 'canonical_html' => $doc0, 'canonical_version' => 0],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $uid,
        ]);
        $mk = function (string $role, int $idx, int $order, string $name) use ($tpl) {
            SignatureRequest::create([
                'signature_template_id' => $tpl->id, 'party_role' => $role, 'role_index' => $idx,
                'signer_name' => $name, 'signer_email' => $role . $idx . '@x.test', 'token' => Str::random(48),
                'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => $order,
            ]);
        };
        $mk('agent', 1, 1, 'Johan Reichel');
        $mk('seller', 1, 2, 'Anine Van der Westhuizen');
        $mk('seller', 2, 3, 'Andre Roets');

        $filled = fn (string $art, string $cid, string $key): bool
            => $sel->rowSlotFilled($tpl->fresh()->document->web_template_data[$art] ?? '', $cid, $key);

        // ── v0: agent + 1st recipient (Anine) initial BOTH amendments (pre-bake → amendSource=merged_html). ──
        foreach ([$cid1, $cid2] as $cid) {
            $svc->recordChangeInitial($tpl->fresh(), $cid, 'Johan Reichel', 'agent', self::PNG);
            $svc->recordChangeInitial($tpl->fresh(), $cid, 'Anine Van der Westhuizen', 'seller', self::PNG);
        }

        // ── The bake: completeWeb freezes the STORED canonical_html at version >= 1 (no regeneration). ──
        $wtd = $doc->fresh()->web_template_data;
        $wtd['canonical_version'] = 1;
        $doc->update(['web_template_data' => $wtd]);

        // ── v1: 2nd recipient (Andre) initials BOTH amendments (post-bake → amendSource=canonical_html). ──
        foreach ([$cid1, $cid2] as $cid) {
            $svc->recordChangeInitial($tpl->fresh(), $cid, 'Andre Roets', 'seller_2', self::PNG);
        }

        // ── The SERVED document is canonical_html. EVERY party's initial must be on EVERY amendment. ──
        foreach ([$cid1, $cid2] as $cid) {
            foreach (['agent', 'seller', 'seller_2'] as $key) {
                $this->assertTrue($filled('canonical_html', $cid, $key),
                    "canonical_html must carry {$key}'s initial on change {$cid} (the served/baked PDF)");
            }
        }
        // The 1st recipient (the historical drop) is present on BOTH amendments in the served canonical.
        $this->assertTrue($filled('canonical_html', $cid1, 'seller'), 'Anine clause-3 initial persisted');
        $this->assertTrue($filled('canonical_html', $cid2, 'seller'), 'Anine clause-4.1 initial persisted (the bug)');
    }
}
