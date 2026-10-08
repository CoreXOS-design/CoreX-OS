<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WebPack;
use App\Models\Docuperfect\WebPackItem;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\TemplateEsignAcknowledgement;
use App\Models\User;
use App\Services\Docuperfect\WebPackSlotResolver;
use App\Exceptions\Docuperfect\WebPackSlotException;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsWetInkDocumentTypes;
use Tests\TestCase;

/**
 * E-sign eligibility is the template's own setting (Johan, 8 Oct 2026). There is no hard block on
 * sale agreements / OTPs / deeds. For document types flagged `esign_warning_required` the ADMIN,
 * in template setup, acknowledges a legal warning when switching e-signing on; that is recorded.
 * Nothing about it is ever shown to an agent.
 *
 * Spec: .ai/specs/ESIGN-CANON.md §7.
 */
final class EsignAcknowledgementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsWetInkDocumentTypes;

    private Agency $agency;
    private User $admin;
    private User $agent;
    private int $otpTypeId;
    private int $mandateTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedWetInkDocumentTypes();

        $this->otpTypeId = (int) DocumentType::query()->where('slug', 'otp')->value('id');
        // The committed snapshot already carries the reference rows migrations insert — reuse, don't re-create.
        $mandate = DocumentType::withTrashed()->firstOrCreate(['slug' => 'mandate'], ['label' => 'Mandate', 'sort_order' => 1, 'is_active' => true]);
        $mandate->forceFill(['deleted_at' => null, 'is_active' => true, 'esign_warning_required' => false])->save();
        $this->mandateTypeId = (int) $mandate->id;

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'HQ']);
        foreach (['template_manager' => ['access_docuperfect', 'manage_templates'], 'floor_agent' => ['access_docuperfect', 'create_docuperfect_docs']] as $role => $keys) {
            Role::create(['name' => $role, 'label' => $role, 'agency_id' => $this->agency->id]);
            foreach ($keys as $key) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], []);
            }
        }
        PermissionService::clearCache();

        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'template_manager', 'is_active' => true, 'name' => 'Nadia Admin']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'floor_agent', 'is_active' => true, 'name' => 'Pieter Agent']);
    }

    private function template(string $name, array $attrs = []): Template
    {
        return Template::create(array_merge([
            'name' => $name,
            'template_type' => 'general',
            'render_type' => 'web',
            'blade_view' => 'docuperfect.templates.' . str()->slug($name),
            'fields_json' => [],
            'agency_id' => $this->agency->id,
            'owner_id' => $this->admin->id,
            'is_esign' => false,
        ], $attrs));
    }

    private function save(Template $t, array $body)
    {
        return $this->actingAs($this->admin)->postJson(route('docuperfect.templates.saveFields', $t->id), $body);
    }

    // ── defaults: nothing flips by itself ─────────────────────────────────────────

    /** @dataProvider saleDocumentNames */
    public function test_a_sale_document_asked_to_be_e_signable_without_acknowledgement_stays_wet_ink(string $name, bool $classified): void
    {
        $t = $this->template($name, ['is_esign' => true, 'document_type_id' => $classified ? $this->otpTypeId : null]);

        $this->assertFalse($t->is_esign, "'{$name}' must keep the wet-ink default when no admin acknowledged the warning");
        $this->assertNull($t->esign_acknowledged_at);
        $this->assertTrue($t->esignAwaitingAcknowledgement());
        $this->assertFalse($t->allowsDeliveryMode('esign'));
    }

    public static function saleDocumentNames(): array
    {
        return [
            'classified OTP'          => ['Enviro Document V13', true],
            'unclassified OTP'        => ['SB 2026 OTP', false],
            'unclassified offer'      => ['Offer To Purchase (V13)', false],
            'contract of sale'        => ['Contract of Sale - Serenity Hills', false],
            'deed of sale'            => ['Deed of Sale', false],
            'koopkontrak'             => ['Koopkontrak', false],
        ];
    }

    /** @dataProvider ordinaryNames */
    public function test_ordinary_documents_are_unaffected(string $name): void
    {
        $t = $this->template($name, ['is_esign' => true]);

        $this->assertTrue($t->is_esign);
        $this->assertFalse($t->requiresEsignAcknowledgement());
        $this->assertFalse($t->esignAwaitingAcknowledgement());
        $this->assertTrue($t->allowsDeliveryMode('esign'));
    }

    public static function ordinaryNames(): array
    {
        return [['Exclusive Authority To Sell (V10)'], ['Sole Mandate'], ['FICA Natural Person'], ['Lease Agreement - Popi'], ['Photoshop Workflow']];
    }

    public function test_the_migration_flips_nothing_that_exists(): void
    {
        $ordinary = $this->template('Sole Mandate', ['is_esign' => true]);
        $sale = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);

        (require base_path('database/migrations/2026_10_16_200000_esign_acknowledgement_for_sale_document_templates.php'))->up();

        $this->assertTrue($ordinary->fresh()->is_esign);
        $this->assertFalse($sale->fresh()->is_esign);
        $this->assertTrue((bool) DocumentType::query()->find($this->otpTypeId)->esign_warning_required);
    }

    public function test_which_types_trigger_the_warning_is_data_not_code(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $this->assertTrue($t->requiresEsignAcknowledgement());

        // The law changes: a tick, not recoding.
        DocumentType::query()->update(['esign_warning_required' => false]);

        $fresh = $this->template('Offer To Purchase 2', ['document_type_id' => $this->otpTypeId, 'is_esign' => true]);
        $this->assertFalse($fresh->requiresEsignAcknowledgement());
        $this->assertTrue($fresh->is_esign, 'with the flag off, e-signing needs no acknowledgement');
        $this->assertSame(0, TemplateEsignAcknowledgement::query()->count());

        // …and an unclassified template named like a sale follows the same flag.
        $byName = $this->template('Contract of Sale', ['is_esign' => true]);
        $this->assertTrue($byName->is_esign);
    }

    // ── template setup: enable with acknowledgement, refused without ─────────────

    public function test_enabling_is_refused_without_acknowledgement(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);

        $this->save($t, ['is_esign' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'esign_ack_required')
            ->assertJsonPath('warning.title', config('esign-acknowledgement.title'));

        $this->assertFalse($t->fresh()->is_esign);
        $this->assertNull($t->fresh()->esign_acknowledged_at);
        $this->assertSame(0, TemplateEsignAcknowledgement::query()->count());
    }

    public function test_enabling_with_acknowledgement_works_and_writes_the_audit_row(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);

        $this->save($t, ['is_esign' => true, 'esign_acknowledged' => true])->assertOk();

        $fresh = $t->fresh();
        $this->assertTrue($fresh->is_esign);
        $this->assertSame($this->admin->id, (int) $fresh->esign_acknowledged_by_user_id);
        $this->assertSame('Nadia Admin', $fresh->esign_acknowledged_by_name);
        $this->assertNotNull($fresh->esign_acknowledged_at);
        $this->assertStringContainsString('Nadia Admin', (string) $fresh->esignAcknowledgementRecord());
        $this->assertTrue($fresh->allowsDeliveryMode('esign'));

        $row = TemplateEsignAcknowledgement::query()->sole();
        $this->assertSame($t->id, (int) $row->template_id);
        $this->assertSame($this->admin->id, (int) $row->user_id);
        $this->assertSame('Nadia Admin', $row->user_name);
        $this->assertSame('enabled', $row->action);
        $this->assertSame('otp', $row->document_type_slug);
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $this->assertNotNull($row->created_at);
        $this->assertStringContainsString('currently does not recognise', (string) $row->wording_snapshot);
        $this->assertSame((int) config('esign-acknowledgement.version'), (int) $row->wording_version);
    }

    public function test_the_audit_row_cannot_be_edited(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $this->save($t, ['is_esign' => true, 'esign_acknowledged' => true])->assertOk();

        $this->expectException(\DomainException::class);
        $row = TemplateEsignAcknowledgement::query()->sole();
        $row->user_name = 'Someone else';
        $row->save();
    }

    public function test_a_non_flagged_type_needs_no_acknowledgement_and_writes_no_audit_row(): void
    {
        $t = $this->template('Sole Mandate', ['document_type_id' => $this->mandateTypeId]);

        $this->save($t, ['is_esign' => true])->assertOk();

        $this->assertTrue($t->fresh()->is_esign);
        $this->assertNull($t->fresh()->esign_acknowledged_at);
        $this->assertSame(0, TemplateEsignAcknowledgement::query()->count());
    }

    public function test_an_acknowledged_template_is_not_asked_again_on_later_saves(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $this->save($t, ['is_esign' => true, 'esign_acknowledged' => true])->assertOk();

        $this->save($t, ['is_esign' => true, 'name' => 'Offer To Purchase v2'])->assertOk();

        $this->assertTrue($t->fresh()->is_esign);
        $this->assertSame(1, TemplateEsignAcknowledgement::query()->count(), 'one acknowledgement, one row');
    }

    public function test_switching_off_is_recorded_and_switching_on_again_needs_a_new_acknowledgement(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $this->save($t, ['is_esign' => true, 'esign_acknowledged' => true])->assertOk();

        $this->save($t, ['is_esign' => false])->assertOk();
        $this->assertFalse($t->fresh()->is_esign);
        $this->assertNull($t->fresh()->esign_acknowledged_at);
        $this->assertSame(['enabled', 'disabled'], TemplateEsignAcknowledgement::query()->orderBy('id')->pluck('action')->all());

        $this->save($t, ['is_esign' => true])->assertStatus(422);
        $this->assertFalse($t->fresh()->is_esign);
    }

    public function test_retyping_an_e_sign_template_as_a_flagged_type_needs_the_acknowledgement(): void
    {
        $t = $this->template('Standard Form', ['document_type_id' => $this->mandateTypeId, 'is_esign' => true]);

        $this->save($t, ['is_esign' => true, 'document_type_id' => $this->otpTypeId])->assertStatus(422);
        $this->assertSame($this->mandateTypeId, (int) $t->fresh()->document_type_id, 'a refused save writes nothing');

        $this->save($t, ['is_esign' => true, 'document_type_id' => $this->otpTypeId, 'esign_acknowledged' => true])->assertOk();
        $this->assertTrue($t->fresh()->is_esign);
        $this->assertSame(1, TemplateEsignAcknowledgement::query()->count());
    }

    public function test_a_copy_of_an_acknowledged_template_starts_on_wet_ink(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $this->save($t, ['is_esign' => true, 'esign_acknowledged' => true])->assertOk();

        $this->actingAs($this->admin)->post(route('docuperfect.templates.copy', $t->id))->assertRedirect();

        $copy = Template::query()->where('name', 'Offer To Purchase (Copy)')->firstOrFail();
        $this->assertFalse($copy->is_esign);
        $this->assertNull($copy->esign_acknowledged_at);
        $this->assertTrue($t->fresh()->is_esign, 'the original is untouched');
    }

    public function test_only_a_template_manager_can_reach_template_setup(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);

        $this->actingAs($this->agent)->postJson(route('docuperfect.templates.saveFields', $t->id), ['is_esign' => true, 'esign_acknowledged' => true])
            ->assertForbidden();
        $this->assertFalse($t->fresh()->is_esign);
    }

    public function test_an_agency_admin_cannot_switch_the_document_type_flag(): void
    {
        $this->actingAs($this->admin)
            ->post(route('docuperfect.settings.types.esign-warning', $this->otpTypeId), ['esign_warning_required' => 0])
            ->assertForbidden();

        $this->assertTrue((bool) DocumentType::query()->find($this->otpTypeId)->esign_warning_required);
    }

    // ── sending for signature ─────────────────────────────────────────────────────

    public function test_an_agent_cannot_start_a_send_for_a_flagged_template_nobody_switched_on_and_is_told_plainly(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $before = Flow::count();

        $res = $this->actingAs($this->agent)->postJson(route('docuperfect.esign.store'), ['template_id' => $t->id]);

        $res->assertStatus(422);
        $this->assertSame($before, Flow::count());
        $this->assertNoLegalWording($res->getContent());
    }

    public function test_sending_works_for_an_enabled_sale_agreement_template_and_shows_the_agent_nothing(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $this->save($t, ['is_esign' => true, 'esign_acknowledged' => true])->assertOk();

        $res = $this->actingAs($this->agent)->postJson(route('docuperfect.esign.store'), ['template_id' => $t->id]);

        $res->assertSuccessful();
        $flow = Flow::latest('id')->firstOrFail();
        $this->assertSame($t->id, (int) $flow->template_id);
        $this->assertNoLegalWording($res->getContent());
        $this->assertContains('esign', $t->fresh()->getEffectiveDeliveryModes());
    }

    public function test_a_web_pack_carries_an_enabled_sale_document_and_refuses_an_unenabled_one_plainly(): void
    {
        $enabled = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $this->save($enabled, ['is_esign' => true, 'esign_acknowledged' => true])->assertOk();
        $off = $this->template('Deed of Sale', ['document_type_id' => $this->otpTypeId]);

        $pack = WebPack::create(['name' => 'Sale Pack', 'agency_id' => $this->agency->id, 'created_by' => $this->admin->id]);
        WebPackItem::create(['web_pack_id' => $pack->id, 'template_id' => $enabled->id, 'sort_order' => 0, 'slot_type' => 'required']);

        $resolved = app(WebPackSlotResolver::class)->resolve($pack, null);
        $this->assertSame([$enabled->id], $resolved->pluck('id')->all());

        $pack2 = WebPack::create(['name' => 'Deed Pack', 'agency_id' => $this->agency->id, 'created_by' => $this->admin->id]);
        WebPackItem::create(['web_pack_id' => $pack2->id, 'template_id' => $off->id, 'sort_order' => 0, 'slot_type' => 'required']);
        try {
            app(WebPackSlotResolver::class)->resolve($pack2, null);
            $this->fail('A template nobody switched e-signing on for must not resolve as sendable.');
        } catch (WebPackSlotException $e) {
            $this->assertNoLegalWording($e->getMessage());
        }
    }

    // ── nothing legal is ever shown to an agent ───────────────────────────────────

    public function test_no_agent_facing_screen_carries_the_warning_or_a_note(): void
    {
        $t = $this->template('Offer To Purchase', ['document_type_id' => $this->otpTypeId]);
        $this->save($t, ['is_esign' => true, 'esign_acknowledged' => true])->assertOk();

        $flow = Flow::create([
            'type' => 'esign', 'template_id' => $t->id, 'user_id' => $this->agent->id,
            'current_step' => 6, 'step_data' => ['template' => ['template_id' => $t->id], 'fields' => []], 'status' => 'active',
        ]);

        $rendered = 0;
        foreach ([6, 1] as $step) {
            $res = $this->actingAs($this->agent)->get(route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => $step]));
            if ($res->status() === 200) {
                $rendered++;
                $this->assertNoLegalWording($res->getContent());
            }
        }
        $create = $this->actingAs($this->agent)->get(route('docuperfect.esign.create'));
        if ($create->status() === 200) {
            $rendered++;
            $this->assertNoLegalWording($create->getContent());
        }
        $this->assertGreaterThan(0, $rendered, 'at least one agent-facing e-sign screen must have rendered for this check to mean anything');
    }

    /**
     * The warning and the acknowledgement record belong to template setup ONLY. Structural guard:
     * the only views/scripts allowed to mention them are the template-setup screens.
     */
    public function test_only_template_setup_files_reference_the_warning_or_the_record(): void
    {
        $allowed = [
            'resources/views/docuperfect/templates/edit.blade.php',
            'resources/views/docuperfect/templates/cds-builder.blade.php',
            'public/js/esign-acknowledgement-modal.js',
            'public/js/docuperfect-editor.js',
        ];
        $needles = ['esign-acknowledgement', 'esignAcknowledgementRecord', 'CoreXEsignAck', 'esign_acknowledged', 'esignAcknowledged'];

        $files = array_merge(
            $this->filesUnder(base_path('resources/views')),
            $this->filesUnder(base_path('public/js')),
        );
        $offenders = [];
        foreach ($files as $file) {
            $rel = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file);
            $rel = str_replace('\\', '/', $rel);
            if (in_array($rel, $allowed, true)) {
                continue;
            }
            $body = (string) file_get_contents($file);
            foreach ($needles as $needle) {
                if (str_contains($body, $needle)) {
                    $offenders[] = "{$rel} mentions {$needle}";
                }
            }
        }

        $this->assertSame([], $offenders, 'The e-sign warning/record must stay inside template setup. ' . implode('; ', $offenders));
    }

    /** @return string[] */
    private function filesUnder(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/\.(php|js)$/', $f->getFilename()) && ! str_contains($f->getPathname(), '/vendor/')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    private function assertNoLegalWording(string $text): void
    {
        $haystack = mb_strtolower($text);
        foreach (['does not recognise', 'legal advice', 'agency decision', 'immovable property', 'alienation of land', 'switched on by', 'legal warning', 'wet-ink per'] as $needle) {
            $this->assertStringNotContainsString($needle, $haystack, "agent-facing output must not carry legal wording ('{$needle}')");
        }
        $this->assertSame(0, preg_match('/\becta\b/', $haystack), 'agent-facing output must not mention ECTA');
    }
}
