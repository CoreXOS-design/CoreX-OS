<div class="header">
    <div class="logo-cell">
        @if($logo)
            <img src="{{ $logo }}" alt="" style="height:48px;max-width:280px;">
        @else
            <span style="font-weight:700;font-size:18px;">{{ $agencyName }}</span>
        @endif
    </div>
    <div class="title-cell">
        <h1>{{ $docTitle }}</h1>
        <p class="muted">{{ $docMeta }}</p>
    </div>
</div>
