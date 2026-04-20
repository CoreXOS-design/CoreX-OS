<?php

use App\Models\Compliance\RmcpSection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (RmcpSection::withoutGlobalScopes()->where('section_number', '26')->get() as $section) {
            $new = str_ireplace(
                'Until further notice, our FICA compliance officer shall be:',
                'Our FICA compliance officer is:',
                $section->body_html
            );
            $section->update(['body_html' => $new]);
        }
    }

    public function down(): void
    {
        // Intentionally empty — we don't want to restore old wording
    }
};
