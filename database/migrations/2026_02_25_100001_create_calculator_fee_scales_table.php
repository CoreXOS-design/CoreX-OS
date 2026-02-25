<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculator_fee_scales', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // 'conveyancing', 'deeds_office', 'transfer_duty'
            $table->json('brackets');
            $table->string('source_document')->nullable();
            $table->date('effective_date')->nullable();
            $table->text('additional_costs_note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // Seed with current 2025 figures
        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('calculator_fee_scales');
    }

    private function seedDefaults(): void
    {
        $now = now();

        $convBrackets = [
            ['max' => 100000, 'fee' => 6435], ['max' => 150000, 'fee' => 7460],
            ['max' => 200000, 'fee' => 8485], ['max' => 250000, 'fee' => 9510],
            ['max' => 290000, 'fee' => 10535], ['max' => 350000, 'fee' => 11560],
            ['max' => 400000, 'fee' => 12585], ['max' => 440000, 'fee' => 13610],
            ['max' => 500000, 'fee' => 14635], ['max' => 600000, 'fee' => 16620],
            ['max' => 700000, 'fee' => 18605], ['max' => 800000, 'fee' => 20590],
            ['max' => 900000, 'fee' => 22575], ['max' => 1000000, 'fee' => 24560],
            ['max' => 1200000, 'fee' => 26545], ['max' => 1400000, 'fee' => 28530],
            ['max' => 1600000, 'fee' => 30515], ['max' => 1800000, 'fee' => 32500],
            ['max' => 2000000, 'fee' => 34485], ['max' => 2200000, 'fee' => 36470],
            ['max' => 2400000, 'fee' => 38455], ['max' => 2600000, 'fee' => 40440],
            ['max' => 2800000, 'fee' => 42425], ['max' => 3000000, 'fee' => 44410],
            ['max' => 3200000, 'fee' => 46395], ['max' => 3400000, 'fee' => 48380],
            ['max' => 3600000, 'fee' => 50365], ['max' => 3800000, 'fee' => 52350],
            ['max' => 4000000, 'fee' => 54335], ['max' => 4200000, 'fee' => 56320],
            ['max' => 4400000, 'fee' => 58305], ['max' => 4600000, 'fee' => 60290],
            ['max' => 4800000, 'fee' => 62275], ['max' => 5000000, 'fee' => 64260],
            ['max' => 5750000, 'fee' => 69260], ['max' => 6000000, 'fee' => 69260],
            ['max' => 6750000, 'fee' => 74260], ['max' => 7000000, 'fee' => 74260],
            ['max' => 7750000, 'fee' => 79260], ['max' => 8000000, 'fee' => 79260],
            ['max' => 8750000, 'fee' => 84260], ['max' => 9000000, 'fee' => 84260],
            ['max' => 9750000, 'fee' => 89260], ['max' => 10000000, 'fee' => 89260],
        ];

        $deedsBrackets = [
            ['max' => 100000, 'fee' => 50], ['max' => 200000, 'fee' => 114],
            ['max' => 300000, 'fee' => 727], ['max' => 600000, 'fee' => 906],
            ['max' => 800000, 'fee' => 1275], ['max' => 1000000, 'fee' => 1464],
            ['max' => 1200000, 'fee' => 1646], ['max' => 2000000, 'fee' => 1646],
            ['max' => 3000000, 'fee' => 2281], ['max' => 4000000, 'fee' => 2281],
            ['max' => 5000000, 'fee' => 2767], ['max' => 6000000, 'fee' => 2767],
            ['max' => 7000000, 'fee' => 3296], ['max' => 8000000, 'fee' => 3296],
            ['max' => 9000000, 'fee' => 3853], ['max' => 10000000, 'fee' => 3853],
        ];

        $dutyBrackets = [
            ['from' => 0, 'to' => 1210000, 'rate' => 0.00],
            ['from' => 1210000, 'to' => 1663800, 'rate' => 0.03],
            ['from' => 1663800, 'to' => 2329300, 'rate' => 0.06],
            ['from' => 2329300, 'to' => 2994800, 'rate' => 0.08],
            ['from' => 2994800, 'to' => 12100000, 'rate' => 0.11],
            ['from' => 12100000, 'to' => 999999999, 'rate' => 0.13],
        ];

        $records = [
            ['type' => 'conveyancing', 'brackets' => json_encode($convBrackets)],
            ['type' => 'deeds_office', 'brackets' => json_encode($deedsBrackets)],
            ['type' => 'transfer_duty', 'brackets' => json_encode($dutyBrackets)],
        ];

        foreach ($records as $rec) {
            \DB::table('calculator_fee_scales')->insert(array_merge($rec, [
                'source_document' => 'Van Dyk & Swart Inc. 2025',
                'effective_date' => '2025-03-01',
                'uploaded_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }
};
