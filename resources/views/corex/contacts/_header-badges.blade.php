{{-- Contact header — TYPE + COMMUNICATION-STATUS badges.
     $commMeta / $commTint are computed once in show.blade.php.
     Params: $justify (string, optional) — flex justification. --}}
<div class="flex items-center gap-2 flex-wrap {{ $justify ?? '' }}">
    @if($contact->type)
    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
        {{ $contact->type->name }}
    </span>
    @endif
    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
          title="{{ $commMeta['title'] ?? '' }}"
          style="background:{{ $commTint }}; color:#fff;">
        {{ $commMeta['label'] }}
    </span>
</div>
