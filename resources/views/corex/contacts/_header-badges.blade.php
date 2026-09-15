{{-- Contact header — TYPE + COMMUNICATION-STATUS badges.
     $commMeta / $commTint are computed once in show.blade.php.
     Params: $justify (string, optional) — flex justification.

     AT-392, 2026-09-11 (cc2's re-report) — a contact holds MANY types, not
     one (e.g. Seller AND Tenant). This used to show only $contact->type,
     the single denormalised primary-type mirror, so a second type a save
     had actually kept was invisible here even though it was correctly
     preserved in the database. Loops the full parentTypes set instead —
     same root fix as the type-picker save hardening in ContactController.
--}}
<div class="flex items-center gap-2 flex-wrap {{ $justify ?? '' }}">
    @forelse($contact->parentTypes as $pt)
    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
        {{ $pt->name }}
    </span>
    @empty
    @if($contact->type)
    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
        {{ $contact->type->name }}
    </span>
    @endif
    @endforelse
    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
          title="{{ $commMeta['title'] ?? '' }}"
          style="background:{{ $commTint }}; color:#fff;">
        {{ $commMeta['label'] }}
    </span>
</div>
