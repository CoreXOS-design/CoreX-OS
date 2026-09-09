@props(['metExpr', 'detailExpr' => null])
{{--
    AT-392, 2026-09-09 — Johan found the authoriser's own version of this
    badge running 53px past its panel on a real screen at 1536px ("Exceeds
    the affordability guideline"), while the agent's side sat comfortably
    inside its panel showing the short "Exceeds guideline". Same concept,
    two independent implementations, one of which never got the earlier
    overflow fix — exactly the bug class this project's rules call out:
    fix the class, not the instance. This component is now the ONLY place
    either role renders this badge; there is no second copy left to drift.

    Deliberately narrow contract: a short, never-wrapping badge plus an
    OPTIONAL separate line underneath for context (property rent, percent
    of gross, whatever the caller needs to say) — the wrapping line is
    what absorbs a long sentence safely; the badge itself never carries
    one. $metExpr/$detailExpr are Alpine JS expression STRINGS (not PHP
    values) — both roles' verdict state is computed client-side, in two
    differently-shaped Alpine components (rentalReview vs
    rentalAssessmentEditor), so the only way to share the actual markup
    is to let each caller supply its own expression against its own data.
    $detailExpr is rendered via x-html, not x-text, deliberately: it's
    always an app-built string composed from currency/percent figures the
    caller already computed, never anything a user typed — no untrusted
    input ever reaches this component, so x-html carries no injection
    risk here, and it's what lets the caller keep a <strong> around the
    amount the way the original agent-side markup did.
--}}
<div class="mt-2">
    <span class="ds-badge" :class="{{ $metExpr }} ? 'ds-badge-success' : 'ds-badge-warning'"
          x-text="{{ $metExpr }} ? 'Within guideline' : 'Exceeds guideline'"></span>
    @if($detailExpr)
        <p class="text-xs mt-1" style="color: var(--text-muted);" x-html="{{ $detailExpr }}"></p>
    @endif
</div>
