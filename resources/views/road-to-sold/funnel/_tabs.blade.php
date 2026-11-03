{{-- AT-454 v2 phase 5 - the strip shared by the team funnel pages. Needs $tab ('grid' | 'flags' | 'suggestions') and $track. --}}
@php $params = \App\Services\RoadToSold\Track::params($track); @endphp
<div class="flex flex-wrap gap-2" role="tablist">
    @foreach(['grid' => ['Team funnel', 'road-to-sold.funnel.index'], 'flags' => ['Training flags', 'road-to-sold.funnel.flags'], 'suggestions' => ['Suggested targets', 'road-to-sold.funnel.suggestions']] as $key => [$label, $route])
        <a href="{{ route($route, $params) }}" class="px-4 py-2 rounded-md text-sm font-semibold"
           style="{{ $tab === $key ? 'background:var(--brand-icon, #0ea5e9); color:#fff;' : 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);' }}">{{ $label }}</a>
    @endforeach
</div>
