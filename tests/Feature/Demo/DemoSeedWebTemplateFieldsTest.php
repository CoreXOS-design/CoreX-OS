<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\Docuperfect\Template;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\WebTemplateSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo seed/refresh leaves every web lease template with its field list filled in. WebTemplateSeeder inserts them with
 * fields_json = [] (template 26 on the demo box had none, so the e-sign wizard had nothing to map).
 */
final class DemoSeedWebTemplateFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_web_template_seeder_alone_leaves_the_field_list_empty(): void
    {
        $this->seed(WebTemplateSeeder::class);

        $web = Template::where('render_type', 'web')->get();
        $this->assertGreaterThan(0, $web->count());
        foreach ($web as $t) {
            $this->assertSame([], (array) $t->fields_json, "{$t->name} starts with no fields - that is the defect");
        }
    }

    public function test_the_demo_seeds_reference_stage_fills_every_web_template_field_list(): void
    {
        // Stage 0 of the real demo seeder, run as the refresh runs it (it seeds the web templates, then must populate their fields).
        $seeder = new DemoDataSeeder();
        $seeder->setCommand(new class extends Command {
            protected $signature = 'noop';
            public function warn($string, $verbosity = null) {}
            public function info($string, $verbosity = null) {}
            public function line($string, $style = null, $verbosity = null) {}
        });
        $m = new \ReflectionMethod($seeder, 'stage0_referenceData');
        $m->setAccessible(true);
        $m->invoke($seeder);

        $web = Template::where('render_type', 'web')->whereNotNull('blade_view')->get();
        $this->assertGreaterThan(0, $web->count(), 'the web templates were seeded');
        foreach ($web as $t) {
            $this->assertNotEmpty((array) $t->fresh()->fields_json, "{$t->name}: fields_json is populated from its blade file");
        }
    }
}
