<?php

namespace App\Services\SaleProbability;

use App\Models\SaleProbabilityRun;
use App\Services\SaleProbability\DTOs\SaleProbabilityInput;
use App\Services\SaleProbability\DTOs\SaleProbabilityResult;
use App\Services\SaleProbability\Signals\DemandSupplySignal;
use App\Services\SaleProbability\Signals\DomMedianSignal;
use App\Services\SaleProbability\Signals\ElasticitySignal;
use App\Services\SaleProbability\Signals\MonthsOfInventorySignal;
use App\Services\SaleProbability\Signals\PriceSqmDeviationSignal;
use App\Services\SaleProbability\Support\ModelConfig;
use App\Services\SaleProbability\Support\ProbabilityMapper;
use App\Services\SaleProbability\Support\ScoreCombiner;
use App\Services\SaleProbability\Support\SensitivityRunner;

class SaleProbabilityService
{
    public function run(
        SaleProbabilityInput $input,
        ?int $createdBy = null,
        bool $persist = true,
    ): SaleProbabilityResult {
        // ── 1. Compute inputs hash ────────────────────────────────────────────
        $canonical  = json_encode($input->toCanonicalArray(), JSON_THROW_ON_ERROR);
        $inputsHash = hash('sha256', $canonical);

        // ── 2. Compute signals ────────────────────────────────────────────────
        $signalInstances = [
            new PriceSqmDeviationSignal(),
            new MonthsOfInventorySignal(),
            new DemandSupplySignal(),
            new DomMedianSignal(),
            new ElasticitySignal(),
        ];

        $signals         = [];
        $requiredMissing = 0;

        foreach ($signalInstances as $signal) {
            $computed                      = $signal->compute($input);
            $signals[$signal::SIGNAL_NAME] = $computed;
            if ($computed['skip'] && $signal::REQUIRED) {
                $requiredMissing++;
            }
        }

        // ── 3. Combine signals into composite score ───────────────────────────
        $combined = ScoreCombiner::combine($signals);

        // ── 4. Build result ───────────────────────────────────────────────────
        $result = SaleProbabilityResult::empty(ModelConfig::MODEL_VERSION, $inputsHash);

        $skipReasons = [];

        if ($requiredMissing >= ModelConfig::REQUIRED_SIGNALS_SKIP_THRESHOLD) {
            // Not enough data to produce reliable probabilities
            $result->skipReason = 'insufficient_signals';
            $skipReasons[]      = 'insufficient_signals';

            $result->setBreakdown([
                'composite_score'          => null,
                'weight_redistribution'    => $combined['weight_redistribution'],
                'weights_used'             => $combined['weights_used'],
                'signals'                  => $combined['signals'],
                'required_signals_missing' => $requiredMissing,
                'skip_reasons'             => $skipReasons,
            ]);
        } else {
            // Sufficient signals — compute probabilities and expected days
            $compositeScore = $combined['composite_score'];
            $probs          = ProbabilityMapper::map($compositeScore);

            $result->p30 = $probs['p30'];
            $result->p60 = $probs['p60'];
            $result->p90 = $probs['p90'];

            $ma              = $input->marketAnalyticsResult;
            $domCurve        = $ma->domCurve;
            $domP50          = is_array($domCurve) ? ($domCurve['p50'] ?? null) : null;
            $domP75          = is_array($domCurve) ? ($domCurve['p75'] ?? null) : null;

            $result->expectedDays = ScoreCombiner::computeExpectedDays(
                compositeScore:       $compositeScore,
                domP50:               $domP50,
                domP75:               $domP75,
                elasticityDaysPerPct: $ma->elasticityDaysPerPct,
                priceDeviationPct:    $ma->pricePerSqmDeviationPct,
            );

            $result->setBreakdown([
                'composite_score'          => $compositeScore,
                'weight_redistribution'    => $combined['weight_redistribution'],
                'weights_used'             => $combined['weights_used'],
                'signals'                  => $combined['signals'],
                'required_signals_missing' => $requiredMissing,
                'skip_reasons'             => $skipReasons,
            ]);

            // ── 4b. Price-sensitivity sweep (runtime only, not persisted) ─────
            $result->sensitivity = SensitivityRunner::run(
                input:            $input,
                signalsBreakdown: $signals,
                computeFn: static function (array $modifiedSignals) use ($ma, $domP50, $domP75): array {
                    $combined = ScoreCombiner::combine($modifiedSignals);
                    $score    = $combined['composite_score'];
                    $probs    = ProbabilityMapper::map($score);
                    return [
                        'composite_score' => $score,
                        'p30'             => $probs['p30'],
                        'p60'             => $probs['p60'],
                        'p90'             => $probs['p90'],
                        'expected_days'   => ScoreCombiner::computeExpectedDays(
                            compositeScore:       $score,
                            domP50:               $domP50,
                            domP75:               $domP75,
                            elasticityDaysPerPct: $ma->elasticityDaysPerPct,
                            priceDeviationPct:    $modifiedSignals['price']['raw'] ?? null,
                        ),
                    ];
                },
            );
        }

        // ── 5. Persist ────────────────────────────────────────────────────────
        if ($persist) {
            SaleProbabilityRun::create([
                'market_analytics_run_id'        => $input->marketAnalyticsRunId,
                'market_analytics_model_version' => $input->marketAnalyticsModelVersion,
                'market_analytics_inputs_hash'   => $input->marketAnalyticsInputsHash,
                'model_version'                  => ModelConfig::MODEL_VERSION,
                'inputs_hash'                    => $inputsHash,
                'inputs_json'                    => $input->toCanonicalArray(),
                'outputs_json'                   => $result->toValuesArray(),
                'breakdown_json'                 => $result->toBreakdownArray(),
                'data_sources_json'              => [
                    'market_analytics' => [
                        'run_id'        => $input->marketAnalyticsRunId,
                        'model_version' => $input->marketAnalyticsModelVersion,
                        'inputs_hash'   => $input->marketAnalyticsInputsHash,
                    ],
                ],
                'created_by' => $createdBy,
            ]);
        }

        return $result;
    }
}
