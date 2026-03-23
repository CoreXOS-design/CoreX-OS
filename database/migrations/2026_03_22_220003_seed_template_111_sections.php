<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Seed sections for Template 111 — "EXCLUSIVE AUTHORITY TO SELL"
        // Sections map to the document's numbered headings
        $sections = [
            [
                'label' => 'Property Description & Authority',
                'description' => 'Preamble identifying the seller, property, and grant of exclusive authority to Home Finders Coastal.',
            ],
            [
                'label' => '1. Domicilum Citandi Et Executandi',
                'description' => 'Agreed addresses for notices and legal communication for all seller parties.',
            ],
            [
                'label' => '2. Terms & Conditions',
                'description' => 'Selling price, mandate period, professional fee, obligations, and seller acknowledgements.',
            ],
            [
                'label' => '3. Show House Security',
                'description' => 'Valuables safety notice during show houses.',
            ],
            [
                'label' => '4. The Home Finders Coastal Pledge',
                'description' => 'Agency commitments including PPRA code of conduct, advertising, buyer identification.',
            ],
            [
                'label' => '5. Protection of Personal Information',
                'description' => 'POPIA consent for processing personal information related to the mandate.',
            ],
        ];

        DB::table('docuperfect_templates')
            ->where('id', 111)
            ->update(['sections' => json_encode($sections)]);
    }

    public function down(): void
    {
        DB::table('docuperfect_templates')
            ->where('id', 111)
            ->update(['sections' => null]);
    }
};
