<?php

namespace Tests\Feature\Platform;

use App\Models\Agency;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformEsignMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AT-447 — nothing in Platform E-Sign (CoreX's own contracts) may be visible to ANY agency.
 * A demo contract (php artisan platform-esign:demo) is seeded, then every way an agency user
 * could reach it is tried: lists, direct ids, model queries, the mode flag, an owner switched
 * into an agency. Only a CoreX owner INSIDE Platform E-Sign mode may see it.
 */
class PlatformEsignIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private const NAME = 'DEMO — CoreX Subscription Agreement';

    private Agency $agencyA;
    private Agency $agencyB;
    private Template $template;
    private Document $document;
    private SignatureTemplate $sig;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        $this->agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $this->agencyB = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]);
        // An ordinary agency template, so "agency data still works" is part of the proof.
        Template::withoutGlobalScopes()->create([
            'name' => 'Agency A Own Mandate', 'template_type' => 'general', 'page_count' => 1,
            'is_esign' => true, 'render_type' => 'pdf', 'agency_id' => $this->agencyA->id,
        ]);

        $this->owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
        $this->assertSame(0, Artisan::call('platform-esign:demo'));

        $this->template = Template::withoutGlobalScopes()->where('name', self::NAME)->firstOrFail();
        $this->document = Document::withoutGlobalScopes()->where('template_id', $this->template->id)->firstOrFail();
        $this->sig = SignatureTemplate::withoutGlobalScopes()->where('document_id', $this->document->id)->firstOrFail();
    }

    private User $owner;

    protected function tearDown(): void
    {
        // The demo's compiled view is a FILE, so the database rollback does not remove it.
        if (isset($this->template) && $this->template->blade_view) {
            @unlink(resource_path('views/' . str_replace('.', '/', $this->template->blade_view) . '.blade.php'));
        }
        Role::clearCache();
        parent::tearDown();
    }

    public function test_the_demo_is_agency_less_and_flagged(): void
    {
        $this->assertNull($this->template->agency_id);
        $this->assertTrue((bool) $this->template->is_platform);
        $this->assertFalse((bool) $this->template->is_global, 'never "shared with every agency"');
        $this->assertNull($this->document->agency_id);
        $this->assertNull($this->sig->agency_id);
        $this->assertSame(1, SignatureRequest::where('signature_template_id', $this->sig->id)->count());
    }

    /** @return array<string, array{0: callable(self): array{0: User, 1: array}}> */
    public static function agencyActors(): array
    {
        return [
            'agency admin'                  => [fn (self $t) => [User::factory()->create(['role' => 'admin', 'agency_id' => $t->agencyA->id]), []]],
            'agency agent'                  => [fn (self $t) => [User::factory()->create(['role' => 'agent', 'agency_id' => $t->agencyA->id]), []]],
            'other agency admin'            => [fn (self $t) => [User::factory()->create(['role' => 'admin', 'agency_id' => $t->agencyB->id]), []]],
            'admin with the mode flag set'  => [fn (self $t) => [User::factory()->create(['role' => 'admin', 'agency_id' => $t->agencyA->id]), [PlatformEsignMode::SESSION_KEY => true]]],
            'owner switched into an agency' => [fn (self $t) => [$t->owner, ['active_agency_id' => $t->agencyA->id]]],
            'owner outside platform mode'   => [fn (self $t) => [$t->owner, []]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agencyActors')]
    public function test_no_agency_actor_can_see_the_platform_contract_anywhere(callable $actor): void
    {
        [$user, $session] = $actor($this);
        $this->actingAs($user)->withSession($session);

        // 1. Every e-sign list / picker page: never contains the demo contract.
        foreach ([
            'docuperfect.dashboard', 'docuperfect.create', 'docuperfect.templates.index', 'docuperfect.esign.create',
            'docuperfect.esign.myDocuments', 'docuperfect.documents.index', 'docuperfect.packs.index',
            'docuperfect.clauses.index', 'docuperfect.field-groups.index', 'docuperfect.recipient-templates.index',
            'docuperfect.leases.index', 'docuperfect.sales',
        ] as $name) {
            $r = $this->get(route($name));
            $this->assertContains($r->getStatusCode(), [200, 302, 403], "$name returned " . $r->getStatusCode());
            $this->assertStringNotContainsString(self::NAME, (string) $r->getContent(), "$name leaked the platform contract");
            $this->assertStringNotContainsString('Subscription Agreement', (string) $r->getContent(), "$name leaked the platform contract");
        }
        $archived = $this->get(route('docuperfect.templates.index', ['status' => 'archived', 'search' => 'DEMO']));
        $this->assertStringNotContainsString(self::NAME, (string) $archived->getContent());

        // 2. By direct id: a 404/403/redirect — never a 200 with the contract.
        foreach ([
            route('docuperfect.templates.edit', $this->template->id),
            route('docuperfect.documents.edit', $this->document->id),
        ] as $url) {
            $r = $this->get($url);
            $this->assertNotSame(200, $r->getStatusCode(), "$url opened the platform contract");
            $this->assertStringNotContainsString(self::NAME, (string) $r->getContent());
        }

        // 3. Starting a signing flow on the platform template is refused.
        $r = $this->postJson(route('docuperfect.esign.store'), ['template_id' => $this->template->id]);
        $this->assertNotSame(200, $r->getStatusCode(), 'an agency could start a flow on the platform template');
        $this->assertStringNotContainsString('/step/', (string) $r->getContent());
        $this->assertSame(0, \App\Models\Docuperfect\Flow::where('template_id', $this->template->id)->count());

        // 4. Model-level: the contract does not exist for this actor.
        $this->assertNull(Template::find($this->template->id));
        $this->assertSame(0, Template::where('name', self::NAME)->count());
        $this->assertSame(0, Template::visibleTo($user)->where('name', self::NAME)->count());
        // (Model queries here run outside the request, so only a plain agency user is checked at
        // model level; the owner cases are covered by the HTTP checks above.)
        if (!$user->isOwnerRole()) {
            $this->assertNull(Document::find($this->document->id));
            $this->assertNull(SignatureTemplate::find($this->sig->id));
        }
    }

    /**
     * REGRESSION (audit): the signer's link is reached by TOKEN. A logged-in agency principal, or the owner
     * testing in his own browser, must still be able to open the CoreX contract — it used to 500 because the
     * agency scopes hid the agency-less document.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('agencyActors')]
    public function test_the_signers_link_works_for_anyone_holding_it_even_when_logged_in(callable $actor): void
    {
        [$user, $session] = $actor($this);
        $token = SignatureRequest::where('signature_template_id', $this->sig->id)->value('token');
        $this->actingAs($user)->withSession($session);

        $r = $this->get('/sign/' . $token);
        $this->assertSame(200, $r->getStatusCode(), 'the signer link must open for a logged-in visitor');
        $this->assertStringContainsString('Subscription Agreement', (string) $r->getContent());
    }

    public function test_the_signers_link_works_logged_out_and_a_wrong_token_is_still_refused(): void
    {
        $token = SignatureRequest::where('signature_template_id', $this->sig->id)->value('token');
        $this->get('/sign/' . $token)->assertOk()->assertSee('Subscription Agreement');
        $this->assertNotSame(200, $this->get('/sign/' . str_repeat('x', 64))->getStatusCode());
    }

    public function test_a_guest_or_api_style_query_never_sees_platform_templates(): void
    {
        // Closed by default: no logged-in user is NOT a reason to show a platform template.
        auth()->logout();
        $this->assertSame(0, Template::where('name', self::NAME)->count());
    }

    public function test_agency_templates_still_work_for_their_agency(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $this->agencyA->id]);
        $this->actingAs($admin);

        $this->get(route('docuperfect.templates.index'))->assertOk()->assertSee('Agency A Own Mandate')->assertDontSee(self::NAME);
    }

    public function test_only_a_corex_owner_inside_platform_mode_sees_it(): void
    {
        $this->actingAs($this->owner)->withSession([PlatformEsignMode::SESSION_KEY => true]);

        $this->get(route('docuperfect.templates.index'))->assertOk()->assertSee(self::NAME)->assertSee('Platform E-Sign');
        // ...and inside the mode no agency's own template appears.
        $this->get(route('docuperfect.templates.index'))->assertDontSee('Agency A Own Mandate');
        // The platform document itself is listed for CoreX inside the mode...
        $this->get(route('docuperfect.dashboard'))->assertOk()->assertSee(self::NAME);
        // ...and the send wizard OFFERS the demo contract (it has a compiled view) but no agency's template.
        $this->get(route('docuperfect.esign.create'))->assertOk()->assertSee('CoreX Subscription Agreement')->assertDontSee('Agency A Own Mandate');
    }

    public function test_a_platform_template_can_never_be_given_an_agency_or_made_global(): void
    {
        $this->template->update(['agency_id' => $this->agencyA->id, 'is_global' => true]);

        $fresh = Template::withoutGlobalScopes()->find($this->template->id);
        $this->assertNull($fresh->agency_id);
        $this->assertFalse((bool) $fresh->is_global);

        // Even after an attempted "make it global", an agency user still cannot see it.
        $this->actingAs(User::factory()->create(['role' => 'admin', 'agency_id' => $this->agencyB->id]));
        $this->assertNull(Template::find($this->template->id));
    }

    public function test_the_demo_is_a_sendable_template_and_an_agency_cannot_start_a_flow_on_it(): void
    {
        $this->assertNotEmpty($this->template->blade_view);
        $this->assertTrue((bool) $this->template->is_esign);
        $this->assertFileExists(resource_path('views/' . str_replace('.', '/', $this->template->blade_view) . '.blade.php'));
        $this->assertFalse($this->template->isEsignBlocked());
    }

    public function test_removing_the_demo_leaves_nothing_behind(): void
    {
        $viewFile = resource_path('views/' . str_replace('.', '/', $this->template->blade_view) . '.blade.php');
        $this->assertSame(0, Artisan::call('platform-esign:demo', ['--remove' => true]));
        $this->assertFileDoesNotExist($viewFile);

        // Archived (soft-deleted), never hard-deleted: no ACTIVE row remains, the archived rows still exist.
        $this->assertSame(0, Template::withoutGlobalScopes()->whereNull('deleted_at')->where('name', self::NAME)->count());
        $this->assertSame(0, Document::withoutGlobalScopes()->whereNull('deleted_at')->where('id', $this->document->id)->count());
        $this->assertSame(0, SignatureTemplate::withoutGlobalScopes()->whereNull('deleted_at')->where('id', $this->sig->id)->count());
        $this->assertSame(1, Template::withoutGlobalScopes()->onlyTrashed()->where('name', self::NAME)->count());
        // And it can be recreated afterwards.
        $this->assertSame(0, Artisan::call('platform-esign:demo'));
        $this->assertSame(1, Template::withoutGlobalScopes()->whereNull('deleted_at')->where('name', self::NAME)->count());
        @unlink(resource_path('views/' . str_replace('.', '/', Template::withoutGlobalScopes()->whereNull('deleted_at')->where('name', self::NAME)->value('blade_view')) . '.blade.php'));
    }
}
