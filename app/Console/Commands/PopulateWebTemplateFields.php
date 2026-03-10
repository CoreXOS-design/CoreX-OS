<?php

namespace App\Console\Commands;

use App\Models\Docuperfect\Template;
use App\Services\WebTemplateFieldPartyMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PopulateWebTemplateFields extends Command
{
    protected $signature = 'populate:web-template-fields';

    protected $description = 'Generate fields_json for web templates from data-field attributes in blade files';

    public function handle()
    {
        $templates = Template::where('render_type', 'web')
            ->whereNotNull('blade_view')
            ->get();

        if ($templates->isEmpty()) {
            $this->warn('No web templates found.');
            return 0;
        }

        foreach ($templates as $template) {
            $this->processTemplate($template);
        }

        $this->info('Done.');
        return 0;
    }

    private function processTemplate(Template $template): void
    {
        $bladePath = $this->resolveBladeFilePath($template->blade_view);

        if (!$bladePath || !File::exists($bladePath)) {
            $this->error("Blade file not found for [{$template->name}]: {$template->blade_view}");
            return;
        }

        $source = File::get($bladePath);

        // Extract all data-field="..." values from blade source
        preg_match_all('/data-field="([^"]+)"/', $source, $matches);
        $fieldNames = array_unique($matches[1]);

        if (empty($fieldNames)) {
            $this->warn("No data-field attributes found in [{$template->name}]");
            return;
        }

        $fields = [];
        foreach ($fieldNames as $fieldName) {
            $party = WebTemplateFieldPartyMap::getPartyForField($fieldName);

            $fields[] = [
                'id'               => 'web_' . $fieldName,
                'type'             => 'placeholder',
                'field_name'       => $fieldName,
                'label'            => $this->humanize($fieldName),
                'named_field_id'   => null,
                'named_field_name' => null,
                'assignedTo'       => $party === 'system' ? 'system' : $party,
                'render_type'      => 'web',
                'pageIndex'        => 0,
            ];
        }

        $template->fields_json = $fields;
        $template->save();

        $total = count($fields);
        $this->info("[{$template->name}] — {$total} fields saved:");
        $counts = collect($fields)->groupBy('assignedTo')->map->count();
        foreach ($counts as $role => $count) {
            $this->line("  {$role}: {$count}");
        }
    }

    private function resolveBladeFilePath(string $bladeView): ?string
    {
        // Convert dot notation to path: docuperfect.web-templates.foo -> resources/views/docuperfect/web-templates/foo.blade.php
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $bladeView) . '.blade.php';
        return resource_path('views' . DIRECTORY_SEPARATOR . $relative);
    }

    private function humanize(string $fieldName): string
    {
        return Str::of($fieldName)
            ->replace('_', ' ')
            ->title()
            ->toString();
    }
}
