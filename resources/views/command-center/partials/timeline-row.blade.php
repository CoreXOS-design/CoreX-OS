@php
    /** @var object $item */
    $model       = $item->model;
    $routeName   = $item->kind === 'event' ? 'command-center.calendar.complete' : 'command-center.tasks.complete';

    // Resolve the pillar link: property → contact → deal (tasks only)
    $titleLink = null;
    if ($item->property) {
        $titleLink = route('corex.properties.show', $item->property);
    } elseif (isset($model->contact) && $model->contact) {
        $titleLink = route('corex.contacts.show', $model->contact);
    } elseif ($item->kind === 'task' && !empty($model->deal_id)) {
        $titleLink = route('deals-v2.show', $model->deal_id);
    }

    $tag = method_exists($model, 'pillarTag') ? $model->pillarTag() : null;
    // Pillar identity tints — not semantic status, so non-token hex is acceptable
    // (per UI_DESIGN_SYSTEM.md §5 rule 10: hex only forbidden where a token exists).
    $tagStyles = [
        'property' => ['fg' => '#f97316', 'label' => 'Property'],
        'deal'     => ['fg' => '#3b82f6', 'label' => 'Deal'],
        'contact'  => ['fg' => '#8b5cf6', 'label' => 'Contact'],
    ];
@endphp
<div class="flex items-start gap-3 py-2 px-2 rounded-md group hover:bg-white/5 transition-colors">
    <div class="flex-shrink-0 text-xs font-mono pt-0.5 tabular-nums whitespace-nowrap" style="color:var(--text-muted); min-width:3.25rem;">
        {{ $item->time_label }}
    </div>
    <div class="flex-shrink-0 w-1 rounded-full self-stretch" style="background:{{ $item->colour }}; min-height:2.25rem;"></div>
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
            @if($titleLink)
                <a href="{{ $titleLink }}" class="text-sm font-medium truncate hover:underline" style="color:var(--text-primary);">
                    {{ $item->title }}
                </a>
            @else
                <p class="text-sm font-medium truncate" style="color:var(--text-primary);">
                    {{ $item->title }}
                </p>
            @endif
            @if($tag && isset($tagStyles[$tag]))
                <span class="ds-badge whitespace-nowrap"
                      style="background:color-mix(in srgb, {{ $tagStyles[$tag]['fg'] }} 15%, transparent); color:{{ $tagStyles[$tag]['fg'] }};">
                    {{ $tagStyles[$tag]['label'] }}
                </span>
            @endif
            @if($item->priority === 'critical')
                <span class="ds-badge ds-badge-danger whitespace-nowrap">Crit</span>
            @elseif($item->priority === 'high')
                <span class="ds-badge ds-badge-warning whitespace-nowrap">High</span>
            @endif
            <span class="ds-badge ds-badge-default whitespace-nowrap">{{ $item->kind }}</span>
        </div>
        @if($item->property)
            <a href="{{ route('corex.properties.show', $item->property) }}"
               class="block text-xs mt-0.5 truncate hover:underline"
               style="color:var(--text-muted);">
                {{ $item->property->buildDisplayAddress() }}
            </a>
        @elseif(isset($model->contact) && $model->contact)
            <a href="{{ route('corex.contacts.show', $model->contact) }}"
               class="block text-xs mt-0.5 truncate hover:underline"
               style="color:var(--text-muted);">
                {{ $model->contact->first_name }} {{ $model->contact->last_name }}
            </a>
        @endif
    </div>
    <div class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
        <form method="POST" action="{{ route($routeName, $model) }}">
            @csrf
            <button type="submit" class="p-1 rounded hover:bg-green-500/10" title="Mark done">
                <svg class="w-4 h-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </button>
        </form>
    </div>
</div>
