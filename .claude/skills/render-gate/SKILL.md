---
name: render-gate
description: The Blade/Alpine render gate (fetch-authenticated-page.php + verify-alpine-render.mjs), the rental smoke and click-through scripts, and the Alpine attribute pitfalls behind them. Use when a change touches a Blade file with Alpine (x-data, x-init, x-show, :bind, @click), a rental review-screen control, or when verify-alpine-render reports an error.
---
# Render gate

**Why:** `php -l`, `view:cache`, PHPUnit and HTTP 200 all pass while the JavaScript a Blade file emits throws on construction and the whole screen is dead (3 rental-applications incidents; the `rentalImages()` Inspections tab). **A 200 is never a pass signal.**

## Who runs what, when
- **Lane, before pushing any Alpine-attribute change (REQUIRED, Standard −1u):**
  ```
  php8.2 scripts/fetch-authenticated-page.php --app-root=/corex-qa1 --user-id=<real fixture id> \
      --url=https://qatesting1.corexos.co.za/<changed route> --out=<scratchpad>/rendered.html --php-bin=php8.2
  node scripts/verify-alpine-render.mjs <scratchpad>/rendered.html
  ```
  `--php-bin` must match the pool that serves the URL (php8.2 for qatesting1; check `/etc/nginx/sites-enabled/`). The fetch goes through the REAL nginx + FPM path, never in-process `Kernel::handle()`.
- **After landing (cc1/conductor, against the deployed site):** the same two commands for every changed route, plus `node scripts/rental-smoke.mjs` (headless Puppeteer over the rental-applications journey; per-screen console-error count + a real-data assertion; zero errors is the pass mark; report per-screen counts, not one pass/fail line) after any pull into `/corex-qa1` touching rental-application views, and `php8.2 scripts/rental-click-through.mjs [--app-root=… --base-url=… --php-bin=php8.2]` for any push touching a rental review/authorisation control (REQUIRED). Click-through mutates state, so it builds and soft-deletes its own throwaway fixtures; its file header lists every control covered and not covered — add a named check in the same push when you add a control. A failing control is a named, dated `KNOWN ISSUE`, never silently passed.
- Lanes do NOT build their own browser harnesses (landing-checks skill).

## What `verify-alpine-render.mjs` asserts (on the REAL rendered HTML)
1. **No leaked attribute/script text** (quote-aware tokenizer) — pass/fail. 2. Inline `x-data` scope check — warning only. 3. **Real execution** of every factory/inline object with its real call-site arguments and zero-arg methods — pass/fail (a `ReferenceError` in construction kills the WHOLE component). 4. **Alpine expression compile** with Alpine's exact wrapper — pass/fail.

## Alpine/Blade pitfalls it exists to catch
- A `//` comment containing a literal `"` inside a quoted attribute closes the attribute early (everything after spills as page text, `SyntaxError` on construction). No `"` in comments inside attributes.
- Alpine only auto-wraps a leading `if (…)` or `let`/`const`. A bare `try {…}`, `for`, `switch`, `function`, `class` or any other multi-statement body in `x-init`/`x-effect`/handlers is a guaranteed `SyntaxError` — put it in a method and call `x-init="doIt()"`.
- `:disabled="row.busy"` bound to `undefined` resolves through `toggleAttribute(name, undefined)` = flips the current state, not false. Initialise booleans.
- A shared `x-ref` inside an `x-for` list is unreliable for `.focus()`.
- **The four sweeps are attribute-scoped, not line-scoped:** for any Alpine attribute touched, scan the FULL value (opening quote → matching close, however many lines) for: (1) `:style` clobbering `style`, (2) a JS comment inside a quoted attribute, (3) a literal `"` inside `x-data="…"`, (4) a multi-root `<template x-if>`/`x-for`. A single-line grep on the diff hunk is exactly the gap that let incident #2 repeat; write a small script to extract multi-line attribute values if needed.

## Fixing the gate's sandbox (not your Blade)
`[SCRIPT EVAL ERROR]` / `.method() ERROR` on a file you didn't touch is usually a mock gap (a universal browser API the Node `vm` sandbox never modelled). Fix `verify-alpine-render.mjs` **permissively** — `fakePermissiveObject()` Proxy for large open-ended surfaces (canvas contexts); concrete stand-ins only for small known shapes (`dataset`, `addEventListener`, `dispatchEvent`, `FormData`) — then re-verify against several previously-passing pages. Known false-fail: `/tools/ad-manager/templates` (fake document lacks a body style; real Chromium is clean).
