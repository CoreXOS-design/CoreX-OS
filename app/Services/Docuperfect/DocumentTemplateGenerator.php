<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\DocumentCustomField;
use App\Models\Docuperfect\Template;
use Illuminate\Support\Str;

class DocumentTemplateGenerator
{
    /**
     * Generate a web template from parsed docx data and confirmed field mappings.
     *
     * @param array  $parsedData     Output from DocxParserService::parse()
     * @param array  $fieldMappings  Confirmed field mappings from user review
     * @param string $templateName   Human-readable template name
     * @param int    $ownerId        User ID of the creator
     * @return Template
     */
    public function generate(array $parsedData, array $fieldMappings, string $templateName, int $ownerId): Template
    {
        $html = $parsedData['html'];
        $bladeHtml = $this->replaceFieldBlanks($html, $fieldMappings);
        $bladeContent = $this->wrapInBladeTemplate($bladeHtml, $templateName);

        // Save blade file
        $slug = Str::slug($templateName) . '-' . time();
        $bladeRelPath = "docuperfect.web-templates.imported.{$slug}";
        $bladeFilePath = resource_path('views/docuperfect/web-templates/imported/' . $slug . '.blade.php');

        // Ensure directory exists
        $dir = dirname($bladeFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($bladeFilePath, $bladeContent);

        // Build fields_json matching existing template format
        // (id, type, label, pageIndex, assignedTo, field_name, render_type)
        $fieldsJson = [];
        foreach ($fieldMappings as $mapping) {
            $fieldName = str_replace('.', '_', $mapping['key']);
            $fieldsJson[] = [
                'id' => 'web_' . $fieldName,
                'type' => 'placeholder',
                'label' => $mapping['label'],
                'pageIndex' => 0,
                'assignedTo' => $mapping['assigned_to'],
                'field_name' => $fieldName,
                'render_type' => 'web',
                'named_field_id' => null,
                'named_field_name' => null,
            ];
        }

        // Create Template record
        $template = Template::create([
            'name' => $templateName,
            'template_type' => 'imported',
            'render_type' => 'web',
            'blade_view' => $bladeRelPath,
            'page_count' => 1,
            'fields_json' => $fieldsJson,
            'is_global' => true,
            'is_esign' => true,
            'owner_id' => $ownerId,
        ]);

        // Create DocumentCustomField records for custom.* fields
        $sortOrder = 0;
        foreach ($fieldMappings as $mapping) {
            if (Str::startsWith($mapping['key'], 'custom.')) {
                DocumentCustomField::create([
                    'template_id' => $template->id,
                    'field_key' => $mapping['key'],
                    'label' => $mapping['label'],
                    'assigned_to' => $mapping['assigned_to'] ?? 'agent',
                    'field_type' => $mapping['field_type'] ?? 'text',
                    'default_value' => $mapping['default_value'] ?? null,
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        return $template;
    }

    /**
     * Replace field-blank spans in HTML with Blade field spans.
     */
    protected function replaceFieldBlanks(string $html, array $fieldMappings): string
    {
        $index = 0;

        return preg_replace_callback(
            '/<span class="field-blank"[^>]*>.*?<\/span>/s',
            function ($match) use ($fieldMappings, &$index) {
                if (!isset($fieldMappings[$index])) {
                    $index++;
                    return $match[0];
                }

                $mapping = $fieldMappings[$index];
                $key = $mapping['key'];
                $varName = str_replace('.', '_', $key);
                $index++;

                return '<span class="field" data-field="' . e($key) . '">{{ $' . $varName . " ?? '' }}</span>";
            },
            $html
        );
    }

    /**
     * Wrap generated HTML body in a full Blade template shell.
     */
    protected function wrapInBladeTemplate(string $bodyHtml, string $templateName): string
    {
        $title = e($templateName);

        return <<<BLADE
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — Home Finders Coastal</title>
    <style>
        @page {
            size: A4;
            margin: 18mm 20mm 15mm 20mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.2;
            color: #1a1a1a;
            background: white;
        }

        p {
            margin: 0 0 2pt 0;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 18mm 20mm 15mm 20mm;
            background: white;
        }

        @media screen {
            body {
                background: #e5e7eb;
            }
            .page {
                box-shadow: 0 2px 16px rgba(0,0,0,0.15);
                margin-top: 20px;
                margin-bottom: 20px;
            }
        }

        @media print {
            body { background: white; }
            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
        }

        .field {
            display: inline-block;
            min-width: 120pt;
            border-bottom: 1px solid #333;
            padding: 1pt 4pt;
            min-height: 14pt;
        }

        .field-short {
            min-width: 40pt;
        }

        strong { font-weight: bold; }
        em { font-style: italic; }
    </style>
</head>
<body>
<div class="page">
    @include('docuperfect.web-templates.components.company-header')

{$bodyHtml}

    @include('docuperfect.web-templates.components.signature-block')
</div>
</body>
</html>
BLADE;
    }
}
