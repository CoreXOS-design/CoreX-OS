<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WebTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name'          => 'Letting Mandate V5',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.letting-mandate-v5',
                'page_count'    => 1,
                'fields_json'   => '[]',
                'is_global'     => true,
            ],
            [
                'name'          => 'Rental Application V8',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.rental-application-v8',
                'page_count'    => 2,
                'fields_json'   => '[]',
                'is_global'     => true,
            ],
            [
                'name'          => 'Letting Mandatory Disclosure V7',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.letting-mandatory-disclosure-v7',
                'page_count'    => 3,
                'fields_json'   => '[]',
                'is_global'     => true,
            ],
            [
                'name'          => 'Letting Marketing Permission V7',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.letting-marketing-permission-v7',
                'page_count'    => 2,
                'fields_json'   => '[]',
                'is_global'     => true,
            ],
            [
                'name'          => 'Lease Agreement POPI V8',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.lease-agreement-popi-v8',
                'page_count'    => 6,
                'fields_json'   => '[]',
                'is_global'     => true,
            ],
            [
                'name'          => 'Commercial Lease Agreement V5',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.commercial-lease-agreement-v5',
                'page_count'    => 7,
                'fields_json'   => '[]',
                'is_global'     => true,
            ],
        ];

        foreach ($templates as $tpl) {
            DB::table('docuperfect_templates')->updateOrInsert(
                ['name' => $tpl['name']],
                $tpl
            );
        }
    }
}
