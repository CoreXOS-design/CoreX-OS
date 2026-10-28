{{-- Onboarding capabilities step — the gradual-introduction choice (AT-478, spec agency-feature-rollout.md §5.2).
     Same block as Settings → Feature rollout (corex.feature-rollout._choice). Saved by
     FeatureRolloutController@saveChoice, registered LAST in this step's savers so the plan holds back
     exactly the features the admin leaves on below. --}}
@php
    $frAgency = $agency ?? \App\Models\Agency::find(auth()->user()?->effectiveAgencyId());
    $frService = app(\App\Services\FeatureRollout\FeatureRolloutService::class);
    $frCanManage = auth()->user()?->hasPermission('manage_feature_rollout');
@endphp
@if ($frAgency && $frCanManage)
    @php
        $frPlan = $frService->forAgency((int) $frAgency->id);
        $frCanStart = \App\Http\Controllers\CoreX\FeatureRolloutController::canStart($frAgency);
    @endphp
    <div class="mb-6">
        @include('corex.feature-rollout._choice', [
            'plan'      => $frPlan,
            'canStart'  => $frCanStart,
            'isBoth'    => \App\Services\Features\AgencyTypeFeatureSeeder::normalise($frAgency->agency_type) === 'both',
            'intervals' => (array) config('feature-rollout.interval_choices', []),
            'defaults'  => $frService->defaults(),
            'preview'   => $frCanStart ? $frService->holdPreview($frAgency) : [],
            'labels'    => collect(app(\App\Services\Features\AgencyFeatureService::class)->catalogue())->map(fn ($d, $k) => $d['label'] ?? $k)->all(),
        ])
    </div>
@endif
