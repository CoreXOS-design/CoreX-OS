# Property — Correct listed date

> Written 2026-10-08 (QA1) to cover the action that arrived on Staging/main with hotfix `d50bc9495`, whose controller cites this file. Behaviour below is what the code does; the permission is the one addition made in this lane.

## What it does and why
The **Listed Date** of a property is the day it went live on the portals. CoreX stamps it itself (first publish; re-publish after deactivation — `Property::listedDateStampOnGoLive()`), and the days-on-market figure counts from it (`App\Services\Properties\DaysOnMarket`: a real listed date always wins; a later portal refresh never moves it; only a later move into an on-market status, or an Imported Stock takeover, starts a new period). When the stamped date is wrong, a person with the permission below corrects it **and must say why**.

## Pillars
Property (writes `listed_date`; reads/writes the property notes and the property audit log). No other pillar.

## User flow
1. Property detail → Edit → under Listed Date, the **Correct listed date** link (only shown to users holding the permission; not shown on new properties or on untouched Imported Stock, where the field is replaced by the takeover flow).
2. Modal: new date (today or earlier) + reason (min 5 characters, required).
3. Save → `PUT /corex/properties/{property}/listed-date` (`corex.properties.listed-date.update`): validates, writes `listed_date`, an audit row `listed_date_corrected` (old → new + reason) and a property note "Listed date changed from X to Y. Reason: …". Same date as today's value is refused with a message.

## Permissions
- Key: **`properties.listed_date.correct`** (module/section `properties`, type `action`) in `config/corex-permissions.php`.
- Default grant: **admin** (all-minus-exclude) and **branch manager** (explicit include); super_admin via `*`. Agents, viewers, office admins and assistants do **not** have it. Change per role in the Role Manager.
- Enforced three ways: route middleware `permission:properties.listed_date.correct`, a controller check, and the link is hidden without it. The ordinary property access scope (`authorizeProperty`) still applies on top.
- Not an agency setting, so not in the Setup Wizard (§10a): it is a per-role grant managed in the Role Manager.

## Files
`app/Http/Controllers/CoreX/PropertyListedDateController.php` · route in `routes/web.php` · modal + link in `resources/views/corex/properties/show.blade.php` · `config/corex-permissions.php` · `app/Services/Properties/DaysOnMarket.php` · tests `tests/Feature/Properties/PropertyListedDateCorrectionTest.php`, `tests/Feature/Intelligence/DaysOnMarketStartDateTest.php`.

## Acceptance
- An agent (even the listing's own agent) gets 403 and nothing changes; branch manager and admin succeed with a reason; missing reason or a future date is refused; reason lands in the notes and audit log.
- The days-on-market figure follows the corrected date everywhere (seller link, Intelligence tab, property header).

## Known, reported (not changed here)
Existing native stock whose listed date was set at capture and is earlier than its first portal activation now counts from that earlier date (on the Staging data checked on 2026-10-08: 44 of 61 native on-market properties, 2–58 days). Whether to back-fill or leave them is a business call.
