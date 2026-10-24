---
name: setup-wizard-rule-10a
description: Non-negotiable 10a - every new agency setting (agencies column, PerformanceSetting key, commission_settings field, toggle/threshold/template/list on /corex/settings) must also be surfaced in the Agency Onboarding Setup Wizard in the same prompt. Use when adding, changing or removing any setting.
---
# Setup Wizard rule 10a

If a prompt adds a **setting** - anything an agency configures about how CoreX behaves for them (new `agencies` column, `PerformanceSetting` key, `commission_settings` field, toggle/threshold/template/list on `/corex/settings` or Company Settings) - the SAME prompt surfaces it in the Agency Onboarding Setup Wizard (`config/agency-onboarding-copy.php`). A setting only on the settings page is NOT done: the wizard is the only place an agency is walked through what CoreX can do, and a switch nobody is told about ships inert.

1. Add the control to the relevant wizard step with its `explain` (what it is, a full sentence) and `affects` ("What this changes:" - a concrete, observable consequence, never a tautology).
2. Wire its canonical saver into that step's `savers`. **Read `.ai/specs/agency-onboarding-setup.md` section 6.1 first:** a wizard step posts a SUBSET of the saver's fields, so a saver that coerces an absent checkbox to `false` silently wipes settings the step never rendered. Guard every boolean write with `$request->has()`.
3. If it genuinely does not belong in onboarding (expert knob, or configured only once running), that is **Johan's call, not the lane's**: ask. If it stays out, say so in the commit message and record it in the spec's "Deliberately NOT in the wizard" list (section 5.1).
4. The reverse holds: a control removed from the settings page is removed from the wizard in the same prompt.
5. Multi-agency (operating rule 9): the default is neutral for a non-HFC agency; wording/documents/branding are settings too. Sidebar Features toggles are one row in `config/corex-features.php` that feeds both the Settings screen and the wizard (skill `sidebar-feature-toggle`).
