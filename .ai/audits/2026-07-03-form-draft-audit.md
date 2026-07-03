# Long-Form Draft-Persistence — AS-BUILT Audit

> **AT-165** (Offline draft persistence). Read-only audit feeding `.ai/specs/offline-draft-persistence.md`.
> No code changed. Investigated 2026-07-03; file:line anchors given. Effort = wiring cost to register a
> form into the shared draft layer (S/M/L).

## Existing client-side persistence (reuse, don't duplicate)
| Pattern | File:line | What it is |
|---|---|---|
| **Client-side draft autosave — THE reference impl** | `resources/views/marketing/hub.blade.php:27-59`, clear at `:110` | Alpine `x-data`, per-record key `corex_mktg_copy_{property.id}`, `init()` restores, `$watch` on 9 fields → `persistCopy()` JSON write, `clearSavedCopy()` on publish. **Per-record keying + field-allowlist already solved here — this is the canonical mixin to generalise.** |
| Server-side draft (not client) | `corex/properties/wizard.blade.php:10-19` → `corex.properties.wizard.draft` (`web.php:2369`) | Wizard writes a real draft `property` record server-side. |
| Server-side draft + per-keystroke field autosave (not client) | `docuperfect/esign/wizard.blade.php:2666-2691` (`/esign/{flow}/draft`) + `:2989-2998` (`/autosave-fields`) | e-sign already autosaves fields to the server. |
| sessionStorage / localStorage UI-state only | `admin/p24/index.blade.php:451-475`, `docuperfect/create.blade.php:5`, `ellie-widget.blade.php:166-259` | Panel open/close, view mode — NOT form drafts. |

**Conclusion:** `marketing/hub` is the only true client draft layer today. The new module generalises it; forms with existing server-side drafts (property wizard, e-sign) only need the client layer to bridge the **pre-server-draft window** (before a draft id exists), not mirror everything.

## Qualifying forms
| # | Form | View | Save endpoint | ~Fields | Wizard | Alpine | New/Edit | Sensitive to EXCLUDE (POPIA) | Effort |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Property capture (simple) | `corex/properties/create-edit.blade.php:43` | `store`/`update` (`web.php:2379,2383`) | ~34 | no | partial | both | — (address/price public-ish) | **M** |
| 2 | Property capture (wizard) | `corex/properties/wizard.blade.php:10` | `wizard.draft` (`web.php:2369`) | ~22 / 4 steps | **yes** | **yes** | new | — | **S** (bridge pre-draft-id only) |
| 3 | CMA / analysis (compute) | `presentations/compute.blade.php:28` | `presentations.compute`, `.snapshots.save` | ~11 | no | no | edit | — | **M** |
| 4 | Presentations builder | `presentations/create.blade.php:30` | `presentations.store` (`web.php:2673`) | ~12 | no | no | new (+edit) | — | **M** |
| 5 | Deal register DR2 | `deals-v2/create.blade.php` | `deals-v2.store` axios (`web.php:616`) | ~30 / multi-step | **yes** | **yes** | new | price/commission internal (not POPIA-block) | **S** |
| 6 | Deal register DR1 | `admin/deals/form.blade.php:77` | `admin.deals.store`/`update` (`web.php:485-493`) | ~25 | no | **no** | both | seller/purchaser names + commission = internal | **L** |
| 7 | **FICA capture** | `fica/form.blade.php:92` | `fica.submit` (token) | **~125, 121 x-model** | no | **yes** | edit/new by `$token` | **HIGH — `personal[id_number]` :123, beneficial-owner `id_number` :227, source-of-funds/bank narrative :797** | **S wire / strict allowlist** |
| 8 | **Staff take-on (HR)** | `staff-take-on/wizard.blade.php` + `_step_*` | `save-step` PATCH (`web.php:1949`) | 8 steps | **yes (8)** | **no** | edit | **HIGH — `tax_reference_number`, bank (`account_holder/bank_name/branch_code/account_number/account_type`), `medical_aid_number` (`_step_tax_banking:13-49`); ID in `_step_personal`** | **L** |
| 9 | Agent onboarding (public) | `onboarding/create.blade.php:22` | `onboarding.store` | ~14 | no | no | new | **`id_number` :58** | **M** |
| 10 | Buyer capture / detail | `command-center/buyers/detail.blade.php` | `buyers.preferences`, `wishlists.*` (`web.php:1304`) | 8 small forms | no | partial | edit | — | **M** |
| 11 | Contact capture (match) | `corex/contacts/_match-form.blade.php:54` | `contacts.matches.store`/`.update` | ~17 | no | partial | both | — | **M** |
| 12 | Contact (prospecting) | `seller-outreach/entry/prospecting-create-contact.blade.php:64` | outreach store (`web.php:2087`) | ~8 | no | **yes** | new/link | **`id_number` :192** | **S** |
| 13 | Rental capture | `rentals/edit.blade.php:15` | `rentals.store`/`update` (`web.php:1055`) | ~15 | no | no | both | tenant/lease PII (moderate) | **M** |
| 14 | Agency / company settings | `admin/company-settings/index.blade.php` | multiple POSTs | **~106 tabbed** | no | no | edit | **exclude any SMTP/API-credential/secret panels** | **L** |
| 15 | E-sign prepare/recipients | `docuperfect/esign/wizard.blade.php` | `/esign/{flow}/draft` + `/autosave-fields` | very large | **yes** | **yes** | edit | recipient emails/IDs | **SKIP — already server-autosaved; do not double-persist** |

## POPIA global exclude (enforce via ALLOWLIST, not denylist)
`id_number`, `passport`, `tax_reference_number`, `account_number`, `branch_code`, `bank_name`, `account_holder`,
`account_type`, `medical_aid_number`, source-of-funds / funding narratives, and any `password`/`api_key`/`secret`/
`token`/SMTP-credential field. FICA (#7) and staff-take-on (#8) carry the bulk of sensitive data, so the layer
persists an **explicit allowlist per form** (mirroring `hub`), never "everything except a denylist".

## Wiring buckets
- **S (already Alpine — add watcher + allowlist):** deals-v2 (#5), property wizard (#2, bridge only), prospecting contact (#12), FICA (#7, strict allowlist).
- **M (plain POST — wrap in x-data or serialize the `<form>`):** property create-edit (#1), presentations create/compute (#3,#4), onboarding (#9), rentals (#13), contact match (#11), buyer detail (#10).
- **L (no Alpine, big surface, most POPIA exposure):** DR1 deals (#6), staff-take-on (#8, per-step keying + sensitive-step exclusion), company-settings (#14, 106 fields + credential panels).
- **Skip / server-owned:** e-sign wizard (#15); property wizard & e-sign bridge the pre-server-draft window only.
