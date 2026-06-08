# Role-Block Contract — One-Time Template Backfill

After commits A (importer normalisation) + B (contract-driven renderer)
land, every NEW imported template carries the `data-role-block` contract
from day one. EXISTING templates need a one-time backfill to bring them
into compliance.

## The command

```bash
php artisan docuperfect:normalize-templates              # apply
php artisan docuperfect:normalize-templates --dry-run    # preview
php artisan docuperfect:normalize-templates --id=111     # single template
```

## What it does

For each CDS template (`template_type = 'cds'`) in the local database:

1. Reads `editor_state.tagged_html` (the CDS builder's saved markup).
2. Walks every `[data-field]` element, derives its role from the field
   name (`seller_address` → role=`seller`), finds the nearest block
   ancestor (`<div>` / `<p>` / `<li>` / `<tr>` etc.), stamps
   `data-role-block="{role}"` on it.
3. Adds `data-role-block-segment="{name}"` derived from the field
   sub-names found inside (identity / address / contact / data).
4. Writes the normalised HTML back to `editor_state.tagged_html`.
5. Re-normalises the generated blade-view file on disk
   (`resources/views/docuperfect/web-templates/cds/template-{id}.blade.php`)
   so the rendered HTML at signing time carries the contract too.
6. Clears the compiled-view cache.

The command is **idempotent** — running it twice produces the same
output. Templates already carrying the contract are reported as
`already normalised` and left untouched.

## When to run

Run **once** per environment after deploying commits A + B. Local first,
then staging, then production.

```bash
# Local (your dev DB)
php artisan docuperfect:normalize-templates --dry-run     # preview output
php artisan docuperfect:normalize-templates               # apply for real

# Then walk the templates Johan tests with: 111, 116, 117, 119.
# Confirm Step 4 preview renders correctly for a multi-seller session.

# Staging
ssh agent@91.99.130.85 'cd /corex-staging && php artisan docuperfect:normalize-templates --dry-run'
ssh agent@91.99.130.85 'cd /corex-staging && php artisan docuperfect:normalize-templates'

# Production (after staging confirmed)
ssh agent@91.99.130.85 'cd /corex && php artisan docuperfect:normalize-templates --dry-run'
ssh agent@91.99.130.85 'cd /corex && php artisan docuperfect:normalize-templates'
```

## Verifying after the backfill

1. Inspect a normalised template's blade file. The opening seller
   paragraph (line ~17 of template-111.blade.php) should now read:

   ```html
   <div class="corex-clause corex-clause-indent-1"
        data-role-block="seller"
        data-role-block-segment="identity">
     ...
   </div>
   ```

2. Render a 2-seller signing session and check the Laravel log.
   The contract-driven path emits a `case: contract` log entry per
   role with the block / group / recipient counts. If you see the
   legacy fallback entry instead (`rendering unnormalised template
   via legacy clustering`), that template needs backfilling:

   ```bash
   php artisan docuperfect:normalize-templates --id=<template_id>
   ```

3. Walk-test in the browser: opening paragraph + main Seller section
   should both render once per recipient with consistent
   `Seller - {Name}` sub-headings.

## When the legacy fallback can be deleted

The legacy clustering path (everything in `RoleBlockExpansionService`
after `if (!$hasContract)`) stays as a safety net during the
transition. Once production logs show no `case: legacy` entries for
~7 days AND every active template has been verified to render
correctly via the contract path, a follow-up commit can delete
the legacy methods entirely:

- `detectBoundariesOnDom`
- `applyBoundary`
- `decomposeFieldsIntoBlockUnits` + `findBlockAncestor`
- `groupConsecutiveBlockUnits`
- `inlineListClusterForRecipients` + `buildRecipientCompositeSpan`
- `resolveCanonicalClusterPerRole`
- `findCleanLca` etc. on the detector

Until then, the legacy code is a safe fallback — it only fires for
un-normalised templates, and the log entry makes those visible for
remediation.
