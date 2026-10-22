{{--
    MIC tab nav, shared across every MIC screen. The tab list is App\Support\Navigation\Hubs\MarketIntelligenceTabs - since
    .ai/specs/real-estate-menu-tabs.md it also carries Deeds capture, Address review and Stale claims (each with its own access check),
    which were their own menu items. A MIC screen whose route is not one of the tabs keeps the Work tab lit, as before.

    Spec: .ai/specs/mic-complete-spec.md §5.2.
--}}
@include('corex.partials._hub-tabs', [
    'hub' => \App\Support\Navigation\Hubs\MarketIntelligenceTabs::class,
    'hubActiveTab' => \App\Support\Navigation\Hubs\MarketIntelligenceTabs::activeKey() ?? 'work',
])
