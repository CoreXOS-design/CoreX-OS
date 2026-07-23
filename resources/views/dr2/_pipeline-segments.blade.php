{{--
    AT-334 (concurrent-lanes rework) — render one stage's ordered segments.

    A segment is either a full-width SEQUENCE POINT (blue left-rail) or a dashed CONCURRENT
    BAND holding lanes, where each lane is a short vertical chain of steps. Every step renders
    as the same uniform tile (dr2._pipeline-step-tile). Params: segments (from
    DealLaneComposer), rowById (id => mapped [model,rag,…]). Inherits $deal, $locked, etc.
--}}
@foreach($segments as $seg)
    @if($seg['type'] === 'sequence')
        @if($rowById->has($seg['step']->id))
            <div class="dr2-seq"><span class="dr2-seq__rail" aria-hidden="true"></span>
                @include('dr2._pipeline-step-tile', ['row' => $rowById[$seg['step']->id], 'variant' => 'wide'])
            </div>
        @endif
    @else
        <div class="dr2-band" role="group" aria-label="Concurrent steps">
            <span class="dr2-band__tag">◇ concurrent</span>
            <div class="dr2-band__lanes">
                @foreach($seg['lanes'] as $lane)
                    <div class="dr2-lane">
                        @foreach($lane as $m)
                            @if($rowById->has($m->id))@include('dr2._pipeline-step-tile', ['row' => $rowById[$m->id], 'variant' => 'lane'])@endif
                            @unless($loop->last)<div class="dr2-lane__link" aria-hidden="true">↓</div>@endunless
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@endforeach
