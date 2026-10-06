<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Support\Compliance\FicaQuestionnaire;
use PHPUnit\Framework\TestCase;

/**
 * The drift guard behind the FICA review screens' "questions as the client saw them".
 *
 * A submission stores only keyed answers, never wording or a form version, so the review
 * screens rely on App\Support\Compliance\FicaQuestionnaire being a faithful record of the
 * public form (resources/views/fica/form.blade.php). This test compares the two in BOTH
 * directions. If it fails you changed a question on one side only: keep the old wording
 * in FicaQuestionnaire under its own dated version and add the new wording from its
 * go-live date (see that class) — do not just "make the test green".
 */
final class FicaQuestionnaireWordingTest extends TestCase
{
    /** Form text that is not a question and so is deliberately not in the catalogue. */
    private const NOT_QUESTIONS = ['Date'];

    private function formSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/fica/form.blade.php');
    }

    private function norm(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);

        return trim((string) $s);
    }

    /** Question-bearing strings extracted from the public form's own markup. */
    private function formStrings(): array
    {
        $src = $this->formSource();
        $out = [];

        // <label class="fica-label">Text <span class="req">*</span></label>
        preg_match_all('/<label class="fica-label">(.*?)<\/label>/s', $src, $m);
        foreach ($m[1] as $t) {
            // the red required-field asterisk is not part of the question
            $out[] = trim((string) preg_replace('/\s*\*$/u', '', $this->norm($t)));
        }

        // radio / checkbox option labels: <label class="fica-(radio|checkbox)-label"><input ...> Text</label>
        preg_match_all('/<label class="fica-(?:radio|checkbox)-label"><input[^>]*>(.*?)<\/label>/s', $src, $m);
        foreach ($m[1] as $t) {
            $out[] = $this->norm($t);
        }

        // section titles, minus the number (it is computed per entity type in the form)
        preg_match_all('/<h2 class="fica-section-title">(.*?)<\/h2>/s', $src, $m);
        foreach ($m[1] as $t) {
            $out[] = preg_replace('/^[\d\s.]+/u', '', $this->norm($t));
        }

        // upload-slot labels from the form's computedUploadTypes()
        preg_match_all('/label:\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', $src, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $out[] = $this->norm(stripslashes($hit[1] !== '' ? $hit[1] : ($hit[2] ?? '')));
        }

        return array_values(array_unique(array_filter($out, fn ($s) => $s !== '' && ! in_array($s, self::NOT_QUESTIONS, true))));
    }

    private function catalogueStrings(): array
    {
        return array_map(
            fn ($s) => preg_replace('/^[\d\s.]+/u', '', $this->norm($s)),
            FicaQuestionnaire::allLabels()
        );
    }

    public function test_every_question_on_the_public_form_is_in_the_catalogue_with_identical_wording(): void
    {
        $catalogue = $this->catalogueStrings();
        $missing = array_values(array_filter($this->formStrings(), fn ($s) => ! in_array($s, $catalogue, true)));

        self::assertSame([], $missing, "These form strings are not in FicaQuestionnaire (new or reworded question?):\n - " . implode("\n - ", $missing));
    }

    public function test_every_catalogue_question_is_still_asked_on_the_public_form(): void
    {
        $form = $this->norm($this->formSource());
        $raw = html_entity_decode($this->formSource(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $gone = [];
        foreach ($this->catalogueStrings() as $label) {
            // 'Please specify...' lives in a placeholder attribute, so also look at the raw source.
            if (! str_contains($form, $label) && ! str_contains($raw, $label)) {
                $gone[] = $label;
            }
        }

        self::assertSame([], $gone, "These FicaQuestionnaire entries are no longer on the public form (removed or reworded question?):\n - " . implode("\n - ", $gone));
    }

    public function test_guard_actually_extracts_a_meaningful_number_of_strings(): void
    {
        // Protects the guard itself: if the form's markup changes shape and the regexes stop
        // matching, the two tests above would pass vacuously.
        self::assertGreaterThan(80, count($this->formStrings()));
        self::assertGreaterThan(80, count($this->catalogueStrings()));
    }

    public function test_every_upload_slot_key_matches_the_forms_own_slot_keys(): void
    {
        preg_match_all("/key:\s*'([a-z_]+)'/", $this->formSource(), $m);
        $formSlots = array_values(array_unique($m[1]));
        sort($formSlots);

        $catalogueSlots = [];
        foreach (array_keys(FicaQuestionnaire::ENTITY_TYPES) as $type) {
            foreach (FicaQuestionnaire::definition($type) as $section) {
                foreach ($section['rows'] as $row) {
                    if (($row['type'] ?? null) === 'doc') {
                        $catalogueSlots[] = $row['slot'];
                    }
                }
            }
        }
        $catalogueSlots = array_values(array_unique($catalogueSlots));
        sort($catalogueSlots);

        self::assertSame($formSlots, $catalogueSlots, 'Upload slot keys differ between the public form and FicaQuestionnaire.');
    }

    public function test_wording_caution_only_for_submissions_before_the_first_recorded_version(): void
    {
        self::assertNull(FicaQuestionnaire::wordingCaution(new \DateTimeImmutable('2026-04-17 12:28:32')));
        self::assertNull(FicaQuestionnaire::wordingCaution(new \DateTimeImmutable(FicaQuestionnaire::EFFECTIVE_FROM . ' 00:00:00')));
        self::assertNull(FicaQuestionnaire::wordingCaution(null));
        self::assertStringContainsString('may differ', (string) FicaQuestionnaire::wordingCaution(new \DateTimeImmutable('2026-03-03 00:00:00')));
    }
}
