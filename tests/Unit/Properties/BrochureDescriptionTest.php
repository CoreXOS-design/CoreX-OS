<?php

namespace Tests\Unit\Properties;

use App\Services\Properties\PropertyBrochureService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The printable brochure must ALWAYS be one A4 page, so the description
 * shrinks to the space the fixed sections leave. These lock the two pure
 * pieces of that logic: the height→char budget, and the word-boundary trim.
 * (The full one-page guarantee also has a render-verify loop in pdf(), covered
 * by manual/integration checks — dompdf rendering is too slow/heavy for a unit.)
 */
class BrochureDescriptionTest extends TestCase
{
    private function call(string $method, ...$args)
    {
        $m = new ReflectionMethod(PropertyBrochureService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(new PropertyBrochureService(), ...$args);
    }

    public function test_char_budget_grows_with_available_height_and_has_a_floor(): void
    {
        $small = $this->call('charBudget', 120);
        $large = $this->call('charBudget', 400);

        // More vertical room → more description allowed.
        $this->assertGreaterThan($small, $large);

        // Never collapses to nothing even when almost no room is left.
        $this->assertGreaterThanOrEqual(160, $this->call('charBudget', 10));
    }

    public function test_long_description_is_trimmed_to_budget_with_ellipsis(): void
    {
        $text = str_repeat('word ', 400); // ~2000 chars, one paragraph
        $out  = $this->call('paragraphs', $text, 300);

        $joined = implode("\n\n", $out);
        $this->assertLessThanOrEqual(302, mb_strlen($joined)); // budget + the ellipsis
        $this->assertStringEndsWith('…', $joined);
    }

    public function test_short_description_is_kept_whole(): void
    {
        $text = "A neat seaside cottage.\n\nWalk to the beach.";
        $out  = $this->call('paragraphs', $text, 900);

        $this->assertSame(['A neat seaside cottage.', 'Walk to the beach.'], $out);
        $this->assertStringNotContainsString('…', implode(' ', $out));
    }
}
