{{-- Prospecting Intelligence — Sale / Rental toggle
     Used inside the price-band segment grid title row.
     Props: $current ('sale' | 'rental'), $urlWith (closure) --}}

<div class="inline-flex rounded overflow-hidden text-[11px]" style="border: 1px solid var(--border);">
    <a href="{{ $urlWith(['listing_type' => 'sale']) }}"
       class="px-2 py-0.5 no-underline transition"
       @if($current === 'sale')
           style="background: var(--brand-button); color: #fff;"
       @else
           style="background: var(--surface); color: var(--text-secondary);"
       @endif>Sale</a>
    <a href="{{ $urlWith(['listing_type' => 'rental']) }}"
       class="px-2 py-0.5 no-underline transition"
       @if($current === 'rental')
           style="background: var(--brand-button); color: #fff;"
       @else
           style="background: var(--surface); color: var(--text-secondary);"
       @endif>Rental</a>
</div>
