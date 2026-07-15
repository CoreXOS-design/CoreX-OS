<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Exceptions\Docuperfect\WebPackSlotException;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WebPack;
use App\Models\Docuperfect\WebPackItem;
use App\Models\User;
use App\Services\Docuperfect\WebPackSlotResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HD-2 / P1-2a — the SERVER resolves a web pack's slots, not the browser.
 *
 * `web_pack_items.slot_type` / `slot_group` / `slot_label` shipped with the admin pack builder,
 * and the wizard has had a slot picker in JS the whole time. Nobody on the server ever read them:
 * store() took the client's `resolved_template_ids` and fed each id straight to Template::find().
 *
 * Three things were therefore taken on the browser's word — each proven closed here:
 *   1. pack membership   — any template id in the database was accepted and sent
 *   2. required slots    — a required document could simply be omitted from the post
 *   3. e-sign legality   — the pack path never ran the ECTA §13(1) block that the
 *                          single-template path runs, so a sale agreement inside a web pack was
 *                          one click from being e-signed, and a sale e-signed is VOID
 *
 * Input paths proven: all-required legacy pack (unchanged) · selectable group picked / unpicked /
 * double-picked · optional in and out · stray id · dropped required doc · blocked doc selected ·
 * blocked doc PRESENT BUT NOT SELECTED (eligibility is about the resolved set, not the pack) ·
 * is_esign off · empty pack · template deleted under the item · pack order preserved.
 */
final class WebPackSlotResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private WebPackSlotResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
        ]);
        $this->actingAs($this->user);

        $this->resolver = new WebPackSlotResolver();
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

    private function pack(string $name = 'Sales Mandate Pack'): WebPack
    {
        return WebPack::create([
            'name'       => $name,
            'agency_id'  => $this->agency->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function item(
        WebPack $pack,
        Template $template,
        string $slotType = 'required',
        ?int $slotGroup = null,
        ?string $slotLabel = null,
        int $sortOrder = 0,
    ): WebPackItem {
        return WebPackItem::create([
            'web_pack_id' => $pack->id,
            'template_id' => $template->id,
            'sort_order'  => $sortOrder,
            'slot_type'   => $slotType,
            'slot_group'  => $slotGroup,
            'slot_label'  => $slotLabel,
        ]);
    }

    /** Every pack built before the slot builder existed is all-required. It must behave exactly as before. */
    public function test_an_all_required_pack_sends_everything_with_no_selection(): void
    {
        $pack = $this->pack();
        $this->item($pack, $this->template('Sole Mandate'), sortOrder: 0);
        $this->item($pack, $this->template('Seller Disclosure'), sortOrder: 10);

        $resolved = $this->resolver->resolve($pack, null);

        $this->assertSame(['Sole Mandate', 'Seller Disclosure'], $resolved->pluck('name')->all());
    }

    /**
     * THE CANONICAL SALES MANDATE PACK (Johan's composition, 2026-07-15) — the whole point of the
     * web-pack system, proven end to end through the resolver. TWO independent selectable groups
     * (Mandate: Open|Exclusive · FICA: Natural|Company) plus one required Disclosure. The agent makes
     * two picks; the Disclosure is never one of them; exactly the three chosen documents go out, in
     * pack order. HD-2's other tests prove single groups — this proves the real pack.
     */
    public function test_the_sales_mandate_pack_resolves_two_independent_choices_plus_the_required_disclosure(): void
    {
        $pack = $this->pack('Sales Mandate Pack');

        // Slot A — Mandate: open OR exclusive.
        $open      = $this->template('Open Mandate');
        $exclusive = $this->template('Exclusive Authority to Sell');
        // Required — Mandatory Disclosure (not the agent's to drop).
        $disclosure = $this->template('Seller Mandatory Disclosure');
        // Slot B — FICA: whichever is applicable to the seller.
        $ficaNatural = $this->template('FICA — Natural Person');
        $ficaCompany = $this->template('FICA — Company');

        $this->item($pack, $exclusive,   'selectable', 1, 'Mandate type',  0);
        $this->item($pack, $open,         'selectable', 1, 'Mandate type',  10);
        $this->item($pack, $disclosure,   'required',   null, null,          20);
        $this->item($pack, $ficaNatural,  'selectable', 2, 'FICA',          30);
        $this->item($pack, $ficaCompany,  'selectable', 2, 'FICA',          40);

        // The agent chooses an Exclusive mandate for a company seller.
        $resolved = $this->resolver->resolve($pack, [$exclusive->id, $ficaCompany->id]);

        $this->assertSame(
            ['Exclusive Authority to Sell', 'Seller Mandatory Disclosure', 'FICA — Company'],
            $resolved->pluck('name')->all(),
            'Exactly the two chosen variants plus the required Disclosure, in pack order.'
        );
        // Neither unchosen variant leaks through.
        $this->assertFalse($resolved->contains('name', 'Open Mandate'));
        $this->assertFalse($resolved->contains('name', 'FICA — Natural Person'));
    }

    /** Leaving EITHER of the mandate pack's two choices unmade is refused, naming the slot that is missing. */
    public function test_the_sales_mandate_pack_refuses_a_half_made_selection(): void
    {
        $pack = $this->pack('Sales Mandate Pack');
        $exclusive   = $this->template('Exclusive Authority to Sell');
        $this->item($pack, $exclusive, 'selectable', 1, 'Mandate type', 0);
        $this->item($pack, $this->template('Open Mandate'), 'selectable', 1, 'Mandate type', 10);
        $this->item($pack, $this->template('Seller Mandatory Disclosure'), 'required', null, null, 20);
        $this->item($pack, $this->template('FICA — Natural Person'), 'selectable', 2, 'FICA', 30);
        $this->item($pack, $this->template('FICA — Company'), 'selectable', 2, 'FICA', 40);

        // Mandate chosen, FICA left unmade.
        $this->expectException(WebPackSlotException::class);
        $this->expectExceptionMessage('Choose which FICA to send');

        $this->resolver->resolve($pack, [$exclusive->id]);
    }

    /** The point of a selectable group: a mandate, and it is either the Sole one or the Open one. */
    public function test_a_selectable_group_sends_exactly_the_chosen_variant(): void
    {
        $pack = $this->pack();
        $disclosure = $this->template('Seller Disclosure');
        $sole = $this->template('Sole Mandate');
        $open = $this->template('Open Mandate');

        $this->item($pack, $disclosure, sortOrder: 0);
        $this->item($pack, $sole, 'selectable', 1, 'mandate', 10);
        $this->item($pack, $open, 'selectable', 1, 'mandate', 20);

        $resolved = $this->resolver->resolve($pack, [$open->id]);

        $this->assertSame(['Seller Disclosure', 'Open Mandate'], $resolved->pluck('name')->all());
        $this->assertFalse($resolved->contains('name', 'Sole Mandate'), 'The unchosen variant must not be sent.');
    }

    /** Sending nothing from the group is not a safe default — refuse, and say which choice was missed. */
    public function test_a_selectable_group_with_no_choice_is_refused_by_name(): void
    {
        $pack = $this->pack();
        $sole = $this->template('Sole Mandate');
        $this->item($pack, $sole, 'selectable', 1, 'mandate', 0);
        $this->item($pack, $this->template('Open Mandate'), 'selectable', 1, 'mandate', 10);

        $this->expectException(WebPackSlotException::class);
        $this->expectExceptionMessage('Choose which mandate to send');

        $this->resolver->resolve($pack, []);
    }

    /** Sending BOTH mandates is not a lesser bug than sending neither — it is a contradiction. */
    public function test_a_selectable_group_refuses_two_choices(): void
    {
        $pack = $this->pack();
        $sole = $this->template('Sole Mandate');
        $open = $this->template('Open Mandate');
        $this->item($pack, $sole, 'selectable', 1, 'mandate', 0);
        $this->item($pack, $open, 'selectable', 1, 'mandate', 10);

        $this->expectException(WebPackSlotException::class);
        $this->expectExceptionMessage('Only one mandate can be sent');

        $this->resolver->resolve($pack, [$sole->id, $open->id]);
    }

    public function test_an_optional_document_is_sent_only_when_asked_for(): void
    {
        $pack = $this->pack();
        $mandate = $this->template('Sole Mandate');
        $fica = $this->template('FICA Consent');
        $this->item($pack, $mandate, sortOrder: 0);
        $this->item($pack, $fica, 'optional', sortOrder: 10);

        $this->assertSame(['Sole Mandate'], $this->resolver->resolve($pack, [])->pluck('name')->all());
        $this->assertSame(
            ['Sole Mandate', 'FICA Consent'],
            $this->resolver->resolve($pack, [$fica->id])->pluck('name')->all()
        );
    }

    /** Hole 1: the browser could post ANY template id and the server sent it. */
    public function test_a_template_that_is_not_in_the_pack_is_refused(): void
    {
        $pack = $this->pack();
        $this->item($pack, $this->template('Sole Mandate'), sortOrder: 0);
        $stranger = $this->template('Someone Else’s Lease');

        $this->expectException(WebPackSlotException::class);
        $this->expectExceptionMessage('not part of this pack');

        $this->resolver->resolve($pack, [$stranger->id]);
    }

    /** Hole 2: a required document could be dropped simply by leaving it out of the post. */
    public function test_a_required_document_is_sent_even_when_the_client_omits_it(): void
    {
        $pack = $this->pack();
        $mandate = $this->template('Sole Mandate');
        $disclosure = $this->template('Seller Disclosure');
        $this->item($pack, $mandate, sortOrder: 0);
        $this->item($pack, $disclosure, sortOrder: 10);

        // The client posts only ONE of the two required documents.
        $resolved = $this->resolver->resolve($pack, [$mandate->id]);

        $this->assertSame(['Sole Mandate', 'Seller Disclosure'], $resolved->pluck('name')->all(),
            'A required document is not the client’s to drop.');
    }

    /**
     * Hole 3 — THE LEGAL ONE. The single-template path hard-blocks alienation documents; the pack
     * path never did. A sale e-signed under ECTA §13(1) is void, so the deal does not exist.
     */
    public function test_an_alienation_document_in_a_pack_is_refused_as_esign_blocked(): void
    {
        $pack = $this->pack();
        $this->item($pack, $this->template('Sole Mandate'), sortOrder: 0);
        $otp = $this->template('Offer to Purchase');   // name-regex layer blocks this
        $this->item($pack, $otp, sortOrder: 10);

        try {
            $this->resolver->resolve($pack, null);
            $this->fail('A sale agreement inside a web pack must never resolve as sendable.');
        } catch (WebPackSlotException $e) {
            $this->assertTrue($e->esignBlocked, 'The refusal must be marked as the LEGAL one, not a slot mistake.');
            $this->assertStringContainsString('wet ink', $e->getMessage());
        }
    }

    /**
     * Eligibility is a question about the RESOLVED set, never about the pack. A pack may legally
     * hold an alienation document as one variant of a choice — it is only illegal if it is the
     * one actually going out.
     */
    public function test_a_blocked_variant_that_is_not_chosen_does_not_block_the_pack(): void
    {
        $pack = $this->pack();
        $mandate = $this->template('Sole Mandate');
        $otp = $this->template('Offer to Purchase');

        $this->item($pack, $mandate, 'selectable', 1, 'document', 0);
        $this->item($pack, $otp, 'selectable', 1, 'document', 10);

        $resolved = $this->resolver->resolve($pack, [$mandate->id]);

        $this->assertSame(['Sole Mandate'], $resolved->pluck('name')->all(),
            'The pack sends fine — the blocked variant was not chosen.');
    }

    public function test_a_template_with_esign_switched_off_is_refused(): void
    {
        $pack = $this->pack();
        $this->item($pack, $this->template('Wet-Ink Only Addendum', isEsign: false), sortOrder: 0);

        $this->expectException(WebPackSlotException::class);
        $this->expectExceptionMessage('not enabled for e-signing');

        $this->resolver->resolve($pack, null);
    }

    public function test_an_empty_pack_is_refused_with_a_clear_message(): void
    {
        $this->expectException(WebPackSlotException::class);
        $this->expectExceptionMessage('no documents in it');

        $this->resolver->resolve($this->pack(), null);
    }

    /** A template soft-deleted out from under a pack item must not 500 the send. */
    public function test_a_deleted_template_is_skipped_not_fatal(): void
    {
        $pack = $this->pack();
        $mandate = $this->template('Sole Mandate');
        $ghost = $this->template('Withdrawn Addendum');
        $this->item($pack, $mandate, sortOrder: 0);
        $this->item($pack, $ghost, sortOrder: 10);

        $ghost->delete();

        $resolved = $this->resolver->resolve($pack, null);

        $this->assertSame(['Sole Mandate'], $resolved->pluck('name')->all());
    }

    /** The pack's own order is the document order — including a variant chosen out of sequence. */
    public function test_the_pack_order_is_preserved(): void
    {
        $pack = $this->pack();
        $cover = $this->template('Cover Letter');
        $open = $this->template('Open Mandate');
        $disclosure = $this->template('Seller Disclosure');

        $this->item($pack, $cover, sortOrder: 0);
        $this->item($pack, $this->template('Sole Mandate'), 'selectable', 1, 'mandate', 10);
        $this->item($pack, $open, 'selectable', 1, 'mandate', 20);
        $this->item($pack, $disclosure, sortOrder: 30);

        $resolved = $this->resolver->resolve($pack, [$open->id]);

        $this->assertSame(['Cover Letter', 'Open Mandate', 'Seller Disclosure'], $resolved->pluck('name')->all(),
            'The chosen variant sits where the pack put it, not appended at the end.');
    }
}
