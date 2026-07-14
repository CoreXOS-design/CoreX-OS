<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WebPack;
use App\Models\Docuperfect\WebPackItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HD-3 — the wizard's REAL request contract, at the HTTP boundary.
 *
 * WebPackSlotResolutionTest proves the resolver. This proves the WIRING: that the browser's actual
 * post shape reaches it, that a refusal comes back as a 422 the wizard can render, and — the part
 * that matters — that NO FLOW IS CREATED when a pack is refused. A resolver that throws while the
 * controller has already written a Flow row would leave a half-built ceremony behind, and the agent
 * would find a draft in their list for a document the system just told them it would not send.
 *
 * The contract is fixed by wizard.blade.php::resolvedPackTemplateIds:
 *   - packs with no slots  → `resolved_template_ids: null`  (every pre-slot pack)
 *   - packs with slots     → a flat array: every required id, the chosen id from each selectable
 *                            group, and any ticked optional id.
 */
final class WebPackStoreEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Uvongo']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
        ]);
        $this->actingAs($this->user);
        $this->withoutVite();
    }

    private function template(string $name, bool $isEsign = true): Template
    {
        return Template::create([
            'name'          => $name,
            'template_type' => 'sales',
            'render_type'   => 'web',
            'blade_view'    => 'docuperfect.templates.' . str()->slug($name),
            'is_esign'      => $isEsign,
            'fields_json'   => [],
        ]);
    }

    private function packWithMandateChoice(): array
    {
        $pack = WebPack::create([
            'name'       => 'Sales Mandate Pack',
            'agency_id'  => $this->agency->id,
            'created_by' => $this->user->id,
        ]);

        $disclosure = $this->template('Seller Disclosure');
        $sole = $this->template('Sole Mandate');
        $open = $this->template('Open Mandate');

        WebPackItem::create(['web_pack_id' => $pack->id, 'template_id' => $disclosure->id, 'sort_order' => 0,  'slot_type' => 'required']);
        WebPackItem::create(['web_pack_id' => $pack->id, 'template_id' => $sole->id,       'sort_order' => 10, 'slot_type' => 'selectable', 'slot_group' => 1, 'slot_label' => 'mandate']);
        WebPackItem::create(['web_pack_id' => $pack->id, 'template_id' => $open->id,       'sort_order' => 20, 'slot_type' => 'selectable', 'slot_group' => 1, 'slot_label' => 'mandate']);

        return [$pack, $disclosure, $sole, $open];
    }

    /** The browser's real post: required ids + the chosen variant. The ceremony carries exactly those. */
    public function test_the_wizard_post_creates_a_flow_carrying_the_resolved_documents(): void
    {
        [$pack, $disclosure, $sole, $open] = $this->packWithMandateChoice();

        $response = $this->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow'         => true,
            'pack_id'             => $pack->id,
            'resolved_template_ids' => [$disclosure->id, $open->id],   // agent chose the Open Mandate
        ]);

        $response->assertSuccessful();

        $flow = Flow::latest('id')->first();
        $this->assertNotNull($flow);
        $this->assertSame(
            [$disclosure->id, $open->id],
            $flow->step_data['template_ids'],
            'The ceremony carries the resolved set — the Sole Mandate was not chosen and must not be in it.'
        );
        $this->assertNotContains($sole->id, $flow->step_data['template_ids']);
    }

    /** A pre-slot pack posts null and must behave exactly as it always has. */
    public function test_a_pack_with_no_slots_posts_null_and_sends_everything(): void
    {
        $pack = WebPack::create([
            'name' => 'Legacy Pack', 'agency_id' => $this->agency->id, 'created_by' => $this->user->id,
        ]);
        $a = $this->template('Mandate');
        $b = $this->template('Disclosure');
        WebPackItem::create(['web_pack_id' => $pack->id, 'template_id' => $a->id, 'sort_order' => 0,  'slot_type' => 'required']);
        WebPackItem::create(['web_pack_id' => $pack->id, 'template_id' => $b->id, 'sort_order' => 10, 'slot_type' => 'required']);

        $this->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow'          => true,
            'pack_id'               => $pack->id,
            'resolved_template_ids' => null,
        ])->assertSuccessful();

        $this->assertSame([$a->id, $b->id], Flow::latest('id')->first()->step_data['template_ids']);
    }

    /**
     * THE ONE THAT MATTERS. A tampered post is refused with a 422 — and, critically, leaves NO
     * Flow behind. The agent does not end up with a draft ceremony for a document the system just
     * refused to send.
     */
    public function test_a_refused_pack_returns_422_and_creates_no_flow(): void
    {
        [$pack, $disclosure] = $this->packWithMandateChoice();
        $stranger = $this->template('Another Agency’s Lease');

        $before = Flow::count();

        $this->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow'          => true,
            'pack_id'               => $pack->id,
            'resolved_template_ids' => [$disclosure->id, $stranger->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains((string) $e, 'not part of this pack'));

        $this->assertSame($before, Flow::count(), 'A refused send must not leave a half-built ceremony behind.');
    }

    /** The legal refusal reaches the browser flagged as legal, so the wizard can say why. */
    public function test_an_alienation_document_returns_the_esign_blocked_flag(): void
    {
        $pack = WebPack::create([
            'name' => 'Bad Pack', 'agency_id' => $this->agency->id, 'created_by' => $this->user->id,
        ]);
        $otp = $this->template('Offer to Purchase');
        WebPackItem::create(['web_pack_id' => $pack->id, 'template_id' => $otp->id, 'sort_order' => 0, 'slot_type' => 'required']);

        $before = Flow::count();

        $this->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow'          => true,
            'pack_id'               => $pack->id,
            'resolved_template_ids' => null,
        ])
            ->assertStatus(422)
            ->assertJsonPath('esign_blocked', true);

        $this->assertSame($before, Flow::count());
    }

    /** An unmade choice is refused at the boundary, naming the slot — not silently resolved. */
    public function test_an_unmade_variant_choice_is_refused_at_the_boundary(): void
    {
        [$pack, $disclosure] = $this->packWithMandateChoice();

        $this->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow'          => true,
            'pack_id'               => $pack->id,
            'resolved_template_ids' => [$disclosure->id],   // no mandate chosen
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains((string) $e, 'Choose which mandate to send'));
    }

    /**
     * Another agency's pack is not reachable at all — AgencyScope 404s it before slots matter.
     *
     * The rival pack is inserted BENEATH the model layer on purpose. `BelongsToAgency::creating()`
     * force-overrides agency_id to the acting user's agency (that is the point of it — an
     * authenticated user cannot spoof a tenant), so building this fixture through the model would
     * quietly stamp the pack as OURS and the test would prove nothing. It is also given a real,
     * sendable item, so a 404 can only mean the scope refused it — not that it was empty.
     */
    public function test_another_agencys_pack_is_not_reachable(): void
    {
        $other = Agency::create(['name' => 'Rival Realty', 'slug' => 'rival-' . uniqid()]);
        $theirTemplate = $this->template('Their Mandate');

        $theirPackId = \DB::table('web_packs')->insertGetId([
            'name'       => 'Their Pack',
            'agency_id'  => $other->id,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('web_pack_items')->insert([
            'web_pack_id' => $theirPackId,
            'template_id' => $theirTemplate->id,
            'sort_order'  => 0,
            'slot_type'   => 'required',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->postJson(route('docuperfect.esign.store'), [
            'is_pack_flow'          => true,
            'pack_id'               => $theirPackId,
            'resolved_template_ids' => null,
        ])->assertStatus(404);

        $this->assertSame(0, Flow::count());
    }
}
