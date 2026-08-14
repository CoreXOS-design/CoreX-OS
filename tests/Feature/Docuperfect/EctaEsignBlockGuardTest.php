<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ECTA §13(1) — an alienation document can never be persisted as e-signable.
 *
 * A sale e-signed under §13(1) is VOID. Not "flagged" — void. The deal does not exist.
 *
 * `is_esign` had SEVEN writers and no guard:
 *   - DocumentTemplateGenerator hardcoded `is_esign => true` on EVERY imported document —
 *     the Offer To Purchase included;
 *   - TemplateController let a user flip the flag from the settings screen, unchecked;
 *   - ESignWizardController "repairs" templates by stamping it true (×3);
 *   - migrations and seeders set it directly.
 * Any one of them could mark a deed of alienation e-signable.
 *
 * And the "four-layer defence" that was supposed to catch it is really ONE layer:
 *   Layer 1 (document_type slug) is DEAD — `docuperfect_document_types` has no `slug` column
 *   on live, staging or qa1, so it has never fired for anyone.
 *   Layer 2 (template_type) is effectively dead — the real values are sales/rental/standard/
 *   general, none of which is in the blocked list, and the importer stamps everything `general`.
 *   Layer 3 (the name regex) carries the entire load.
 *
 * So the rule now lives where the data does: if the law blocks it, `is_esign` cannot be true
 * when it hits the database — no matter who is writing, or how.
 */
final class EctaEsignBlockGuardTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $name, array $attrs = []): Template
    {
        return Template::create(array_merge([
            'name' => $name,
            'template_type' => 'general',
            'render_type' => 'web',
            'is_esign' => true,          // every caller below TRIES to make it e-signable
            'owner_id' => $this->userId(),
        ], $attrs));
    }

    private function userId(): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Elize van Wyk', 'email' => 'elize-' . Str::random(6) . '@hfcoastal.co.za',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** THE IMPORT PATH: the generator hardcoded is_esign=true. An OTP must land wet-ink. */
    public function test_an_imported_otp_cannot_be_created_e_signable(): void
    {
        $t = $this->template('Offer To Purchase (V13) — WET INK ONLY');

        $this->assertFalse($t->is_esign, 'an OTP must never be created e-signable');
        $this->assertDatabaseHas('docuperfect_templates', ['id' => $t->id, 'is_esign' => 0]);
    }

    /** THE SETTINGS SCREEN: a user flipping the toggle must not be able to override the law. */
    public function test_a_user_cannot_flip_an_otp_to_e_signable(): void
    {
        $t = $this->template('Shelly HFC OTP (V13) - Enviro Clause');

        $t->update(['is_esign' => true]);          // exactly what TemplateController does

        $this->assertFalse($t->fresh()->is_esign, 'the settings screen must not be able to unblock a sale');
    }

    /** THE WIZARD "REPAIR" PATH: ESignWizardController stamps is_esign=true in three places. */
    public function test_the_wizard_repair_path_cannot_unblock_a_sale(): void
    {
        $t = $this->template('Deed of Sale — Erf 1123');

        $t->update(['is_esign' => true]);

        $this->assertFalse($t->fresh()->is_esign);
    }

    /**
     * THE LIVE HOLE. "Contract of Sale - Serenity Hills Eco Estate" exists on live TODAY and
     * the old regex did not match it — an alienation document one toggle away from being
     * e-signed and void.
     */
    public function test_contract_of_sale_is_blocked(): void
    {
        $t = $this->template('Contract of Sale - Serenity Hills Eco Estate');

        $this->assertTrue($t->isEsignBlocked(), '"Contract of Sale" IS a deed of alienation');
        $this->assertFalse($t->is_esign);
    }

    /** @dataProvider alienationNames */
    public function test_every_way_south_africans_name_an_alienation_document_is_blocked(string $name): void
    {
        $t = $this->template($name);

        $this->assertTrue($t->isEsignBlocked(), "'{$name}' must be blocked under ECTA §13(1)");
        $this->assertFalse($t->is_esign);
    }

    public static function alienationNames(): array
    {
        return [
            'OTP'                      => ['SB 2026 OTP'],
            'offer to purchase'        => ['Offer To Purchase (V13)'],
            'deed of sale'             => ['Deed of Sale'],
            'deed of alienation'       => ['Deed of Alienation'],
            'deed of transfer'         => ['Deed of Transfer'],
            'sale agreement'           => ['Sale Agreement 2026'],
            'agreement of sale'        => ['Agreement of Sale — Unit 4'],
            'purchase agreement'       => ['Purchase Agreement'],
            'contract of sale'         => ['Contract of Sale - Serenity Hills'],
            'sale of immovable prop'   => ['Sale of Immovable Property'],
            'koopkontrak'              => ['Koopkontrak'],
        ];
    }

    /**
     * THE OTHER HALF, AND JUST AS IMPORTANT: a MANDATE is not an alienation document. It
     * AUTHORISES a sale; it does not effect one. Blocking it would break the launch document.
     *
     * @dataProvider esignableNames
     */
    public function test_documents_that_may_lawfully_be_e_signed_are_not_blocked(string $name): void
    {
        $t = $this->template($name);

        $this->assertFalse($t->isEsignBlocked(), "'{$name}' is NOT an alienation document — it must stay e-signable");
        $this->assertTrue($t->is_esign, 'the guard must not touch a lawful e-sign document');
    }

    public static function esignableNames(): array
    {
        return [
            'the launch mandate'   => ['Exclusive Authority To Sell (V10)'],
            'sole mandate'         => ['Sole Mandate — Shelly Beach'],
            'dual mandate'         => ['SB 2026 Dual Mandate'],
            'FICA'                 => ['FICA Natural Person (V8)'],
            'disclosure'           => ['Seller Mandatory Disclosure (V7)'],
            'letting mandate'      => ['Letting Mandate (V5)'],
            'lease'                => ['Lease Agreement - Popi (V8)'],
            'the Photoshop guard'  => ['Photoshop Workflow'],
        ];
    }

    /** The guard must not fight a template that is already, correctly, wet-ink. */
    public function test_a_blocked_template_saved_as_wet_ink_stays_wet_ink_without_drama(): void
    {
        $t = $this->template('Offer To Purchase (V13)', ['is_esign' => false]);

        $t->update(['name' => 'Offer To Purchase (V13) — renamed']);

        $this->assertFalse($t->fresh()->is_esign);
    }

    /**
     * THE LAUNDERING ATTACK — and the reason classification exists.
     *
     * An UNCLASSIFIED sale is protected only by its name, so renaming it launders it into an
     * e-signable document. (I pinned that as an honest limit before the classifier existed.)
     *
     * A CLASSIFIED sale is protected by what it IS. Rename it to anything you like — it stays
     * blocked, because Layer 1 reads the document type, not the label.
     */
    public function test_a_classified_sale_survives_being_renamed_to_something_innocuous(): void
    {
        $otpType = DocumentType::query()->where('slug', 'offer_to_purchase')->value('id');

        $t = $this->template('Offer To Purchase (V13)', ['document_type_id' => $otpType]);
        $this->assertFalse($t->is_esign);

        // The attack: rename it past the regex, then turn e-sign on.
        $t->update(['name' => 'Enviro Document V13', 'is_esign' => true]);

        $this->assertTrue($t->fresh()->isEsignBlocked(), 'classification must outlive the name');
        $this->assertFalse($t->fresh()->is_esign, 'a renamed sale must STILL be wet-ink');
    }

    /** An UNCLASSIFIED sale is only as safe as its name — which is exactly why we classify. */
    public function test_an_unclassified_sale_is_only_protected_by_its_name(): void
    {
        $t = $this->template('Offer To Purchase (V13)');   // no document_type_id
        $this->assertFalse($t->is_esign);

        $t->update(['name' => 'Enviro Document V13', 'is_esign' => true]);

        $this->assertTrue(
            $t->fresh()->is_esign,
            'this is the hole classification closes — an unclassified document is what it is CALLED'
        );
    }
}
