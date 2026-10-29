# CoreX audit plan — November 2026

> Set by Johan, 2026-10-29. The audit is done in legs, one pillar at a time. Every leg is READ-ONLY (no code, no data changes),
> written in plain business language, and uses the section template below so the legs can be read side by side.

## Why

Road to Sold, presentations and property evaluations are only as good as the data underneath them. Each leg checks whether the
data is arriving, whether it means what we think it means, and whether agents are getting the most out of it for their clients.

## Section template (use for EVERY source in EVERY leg, in this order)

Every source (a place data enters CoreX) answers three questions, in this order:

1. **(a) Where are we getting the data from?** The entry point (screen, extension, email, import, feed), who or what triggers it,
   how often, and what we actually save on arrival. Real row counts from QA1 where they exist.
2. **(b) What does the data mean?** What each saved item stands for in the real world (for example: a portal reference is a
   listing, not a property; an erf number is a registered stand, not a unit), how reliable it is, and what it cannot tell us.
3. **(c) How do we put it to best use for an agent, to increase service to clients?** What the agent could do with it today,
   what they cannot do because of a gap, and the smallest change that would unlock it.

Rules for writing a leg:

- Summary sections are plain business language: no file paths, line numbers, column or function names. Johan is not a programmer.
- All technical detail (file:line, tables, columns, SQL, thresholds) goes in a SEPARATE appendix file for the leg, named
  `AUDIT_<LEG>_<DATE>_APPENDIX.md`, linked from the top of the main report.
- Every claim in the appendix cites file:line or the SQL that proves it. Anything not verified is marked UNVERIFIED. Never guess.
- Each leg ends with gaps ranked by impact, and the smallest change that unlocks the most.
- Read-only: QA1 data and code are not changed. The only commit is the report itself, made from a worktree (never inside `/corex-qa1`).

## Legs

| Leg | Scope | Status | Report |
|-----|-------|--------|--------|
| 1 | Properties and matching — how a property enters CoreX from each source and whether it is linked with confidence (the start of Road to Sold depends on this; correct MIC and deeds data also feed presentations and property evaluation accuracy) | In progress (2026-10-29) | `.ai/AUDIT_PROPERTIES_MATCHING_2026-10-29.md` |
| 2 | Contacts | Placeholder — to be scoped | — |
| 3 | Deals | Placeholder — to be scoped | — |
| 4 | Agents | Placeholder — to be scoped | — |
| 5+ | Other areas (to be decided by Johan) | Placeholder | — |
