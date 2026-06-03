{{-- Private Property admin — shared tab nav.
     Link-based tabs (not Alpine panels) so the slow GetAllAgentsForBranch SOAP
     call behind "PP Branch Profiles" only fires when that tab is opened. --}}
@php
    $ppTabs = [
        ['route' => 'admin.pp.agent-mapping', 'label' => 'Agents'],
        ['route' => 'admin.pp.agents',        'label' => 'PP Branch Profiles'],
        ['route' => 'admin.pp.mapping-email', 'label' => 'Mapping Email'],
    ];
@endphp
<div class="flex gap-1 rounded-md p-1 flex-wrap" style="background: var(--surface); border:1px solid var(--border);">
    @foreach($ppTabs as $tab)
        @continue(!\Illuminate\Support\Facades\Route::has($tab['route']))
        @php $isActive = request()->routeIs($tab['route']); @endphp
        <a href="{{ route($tab['route']) }}"
           class="flex-1 sm:flex-none text-center px-4 py-2 text-sm font-medium rounded-md transition-colors"
           style="{{ $isActive ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'color: var(--text-secondary);' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
