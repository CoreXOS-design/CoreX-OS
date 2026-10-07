{{-- Own-FICA separation — shown instead of the review / mark-up controls when the logged-in
     officer may not review this FICA (FicaSubmission::ownReviewBlockFor()). The same rule is
     enforced server-side at the start of every review route; this is the plain explanation,
     never a dead end. Expects $reason (string). --}}
<div class="rounded-md p-5 text-sm" data-own-fica-blocked
     style="background:color-mix(in srgb, var(--ds-amber,#f59e0b) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber,#f59e0b) 40%, transparent); color:var(--text-primary);">
    <p class="font-semibold">This is your own FICA</p>
    <p class="mt-1 text-xs" style="color:var(--text-secondary);">{{ $reason }}</p>
</div>
