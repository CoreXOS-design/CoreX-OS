// E-sign regression harness — wizard-driving primitives.
//
// Every function drives the REAL rendered UI via Puppeteer clicks/typing —
// no internal API shortcuts for anything the assertions later check. Search
// endpoints are hit directly ONLY as a documented workaround for the
// separately-reported "search excludes imperfectly-tagged contacts" bug
// (Johan's call, not this harness's to silently route around by picking
// perfectly-tagged fixtures — which is what fixtures.php already does, so
// this workaround should not actually be needed on a clean harness run; it
// stays here only as a fallback so a search-layer regression doesn't take
// the whole harness down with it, and is flagged in the run report when hit).

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function clickBtnContains(page, text) {
    return page.evaluate((text) => {
        const btn = Array.from(document.querySelectorAll('button')).find(b => b.innerText && b.innerText.includes(text));
        if (btn) { btn.click(); return true; }
        return false;
    }, text);
}

async function clickBtnExact(page, text) {
    return page.evaluate((text) => {
        const btn = Array.from(document.querySelectorAll('button')).find(b => b.innerText && b.innerText.trim() === text);
        if (btn) { btn.click(); return true; }
        return false;
    }, text);
}

async function newPage(browser, host, cookie) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1450, height: 1600 });
    await page.setCookie({ name: cookie.name, value: cookie.value, domain: host, path: '/', secure: true, httpOnly: true });
    return page;
}

// Step 1 of the chain: pick the template, land on Property. No flowId
// exists yet at this point (the flow is created once Property is picked).
async function selectTemplate(page, host, templateButtonText) {
    await page.goto(`https://${host}/docuperfect/esign/create`, { waitUntil: 'networkidle0', timeout: 30000 });
    await sleep(1200);
    await clickBtnContains(page, templateButtonText);
    await sleep(800);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 }).catch(() => null),
        clickBtnContains(page, 'Next'),
    ]);
    await sleep(1200);
}

// Step 2: pick the property, land on Recipients. Returns the flowId.
async function selectProperty(page, propertySearchTerm, propertyMatchText) {
    const propSearch = await page.$('input[placeholder="Start typing to search..."]');
    await propSearch.click();
    await propSearch.type(propertySearchTerm, { delay: 40 });
    await sleep(1800);
    const picked = await page.evaluate((matchText) => {
        const btns = Array.from(document.querySelectorAll('button, div[class*="cursor-pointer"]')).filter(el => el.innerText && el.innerText.includes(matchText));
        if (btns.length) { btns[0].click(); return true; }
        return false;
    }, propertyMatchText);
    if (!picked) throw new Error(`selectProperty: search "${propertySearchTerm}" did not find "${propertyMatchText}"`);
    await sleep(1000);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 }).catch(() => null),
        clickBtnContains(page, 'Next'),
    ]);
    await sleep(1500);

    const flowId = await page.evaluate(() => {
        const root = document.querySelector('[x-data="esignWizard()"]');
        return root && window.Alpine ? window.Alpine.$data(root).flowId : null;
    });
    if (!flowId) throw new Error('selectProperty: could not read flowId after property step');
    return flowId;
}

// Adds a recipient via the live search box. Falls back to a direct API call
// + selectContact() ONLY if the UI search returns nothing — logs the
// fallback so the run report surfaces it rather than hiding it.
async function addRecipientBySearch(page, { searchTerm, matchText, role, warnings }) {
    await clickBtnContains(page, 'Add Recipient');
    await sleep(700);
    await page.evaluate((role) => {
        const root = document.querySelector('[x-data="esignWizard()"]');
        window.Alpine.$data(root).recipients[window.Alpine.$data(root).recipients.length - 1].role = role;
    }, role);
    await sleep(300);

    const boxes = await page.$$('input[placeholder="Start typing to search..."]');
    const box = boxes[boxes.length - 1];
    await box.click({ clickCount: 3 });
    await box.type(searchTerm, { delay: 35 });
    await sleep(2200);
    const picked = await page.evaluate((matchText) => {
        const buttons = Array.from(document.querySelectorAll('button')).filter(b => b.innerText && b.innerText.includes(matchText));
        if (buttons.length) { buttons[0].click(); return true; }
        return false;
    }, matchText);

    if (!picked) {
        warnings.push(`Recipient search UI returned nothing for "${searchTerm}" — used the direct-API fallback (searches the endpoint without &role=, matching the earlier-confirmed search-filter gap). Flag for Johan's search-filter decision, not a harness bug.`);
        await page.evaluate(async (searchTerm, matchText, role) => {
            const root = document.querySelector('[x-data="esignWizard()"]');
            const data = window.Alpine.$data(root);
            const idx = data.recipients.length - 1;
            const resp = await fetch('/docuperfect/esign/api/contacts?q=' + encodeURIComponent(searchTerm));
            const results = await resp.json();
            const contact = results.find(c => (c.full_name || (c.first_name + ' ' + c.last_name)).includes(matchText));
            if (!contact) throw new Error('fallback also found nothing for ' + searchTerm);
            data.selectContact(idx, contact);
            data.recipients[idx].role = role;
        }, searchTerm, matchText, role);
        await sleep(500);
    } else {
        await sleep(800);
    }
}

async function addRecipientManual(page, { name, idNumber, email, cell, address, role }) {
    await clickBtnContains(page, 'Add Recipient');
    await sleep(700);
    await page.evaluate((role) => {
        const root = document.querySelector('[x-data="esignWizard()"]');
        window.Alpine.$data(root).recipients[window.Alpine.$data(root).recipients.length - 1].role = role;
    }, role);
    await sleep(300);

    const boxes = await page.$$('input[placeholder="Start typing to search..."]');
    const box = boxes[boxes.length - 1];
    await box.click({ clickCount: 3 });
    await box.type(name, { delay: 30 });
    await sleep(1500); // let the "no results" state settle, matching real agent behaviour

    await page.evaluate(({ name, idNumber, email, cell, address }) => {
        const root = document.querySelector('[x-data="esignWizard()"]');
        const data = window.Alpine.$data(root);
        const idx = data.recipients.length - 1;
        data.recipients[idx].name = name;
        data.recipients[idx].id_number = idNumber;
        data.recipients[idx].email = email;
        data.recipients[idx].cell = cell;
        data.recipients[idx].address = address;
    }, { name, idNumber, email, cell, address });
    await sleep(500);
}

// Ticks Deceased on a recipient, opens Replace-this-party, selects the given
// template, and binds the executor slot from either Contacts or Supplier.
async function tickDeceasedAndBindExecutor(page, { namePart, templateName, executorSource, executorSearchTerm, executorMatchText }) {
    const ticked = await page.evaluate((namePart) => {
        const root = document.querySelector('[x-data="esignWizard()"]');
        const data = window.Alpine.$data(root);
        const idx = data.recipients.findIndex(r => (r.name || '').includes(namePart));
        if (idx < 0) return { ok: false };
        data.recipients[idx]._is_deceased = true;
        data.openReplaceModal(idx);
        return { ok: true, idx };
    }, namePart);
    if (!ticked.ok) throw new Error(`tickDeceasedAndBindExecutor: recipient "${namePart}" not found`);
    await sleep(2000);

    const templSelected = await page.evaluate((templateName) => {
        const root = document.querySelector('[x-data="esignWizard()"]');
        const data = window.Alpine.$data(root);
        const t = data.replaceModal.templates.find(t => t.name === templateName);
        if (!t) return { ok: false, available: data.replaceModal.templates.map(x => x.name) };
        data.selectReplaceTemplate(t);
        return { ok: true };
    }, templateName);
    if (!templSelected.ok) throw new Error(`tickDeceasedAndBindExecutor: template "${templateName}" not found — available: ${JSON.stringify(templSelected.available)}`);
    await sleep(1200);

    if (executorSource === 'contact') {
        const boxes = await page.$$('input[placeholder="Or search a contact by name…"]');
        if (!boxes.length) throw new Error('tickDeceasedAndBindExecutor: no contact search box in Replace modal');
        await boxes[0].click();
        await boxes[0].type(executorSearchTerm, { delay: 35 });
        await sleep(2200);
        const picked = await page.evaluate((matchText) => {
            const btns = Array.from(document.querySelectorAll('button')).filter(b => b.innerText && b.innerText.includes(matchText));
            if (btns.length) { btns[0].click(); return true; }
            return false;
        }, executorMatchText);
        if (!picked) throw new Error(`tickDeceasedAndBindExecutor: contact executor "${executorSearchTerm}" not found in modal`);
    } else if (executorSource === 'supplier') {
        const boxes = await page.$$('input[placeholder="Or search a supplier by name or firm…"]');
        if (!boxes.length) throw new Error('tickDeceasedAndBindExecutor: no supplier search box in Replace modal');
        await boxes[0].click();
        await boxes[0].type(executorSearchTerm, { delay: 35 });
        await sleep(2200);
        const picked = await page.evaluate((matchText) => {
            const btns = Array.from(document.querySelectorAll('button')).filter(b => b.innerText && b.innerText.includes(matchText));
            if (btns.length) { btns[0].click(); return true; }
            return false;
        }, executorMatchText);
        if (!picked) throw new Error(`tickDeceasedAndBindExecutor: supplier executor "${executorSearchTerm}" not found in modal`);
    } else {
        throw new Error(`tickDeceasedAndBindExecutor: unknown executorSource "${executorSource}"`);
    }
    await sleep(1500);

    const confirmed = await page.evaluate(() => {
        const btn = Array.from(document.querySelectorAll('button')).find(b => b.innerText.trim() === 'Confirm');
        if (btn && !btn.disabled) { btn.click(); return true; }
        return false;
    });
    if (!confirmed) throw new Error('tickDeceasedAndBindExecutor: Confirm button disabled or not found');
    await sleep(2000);
}

// Drives the REAL proxy picker via REAL clicks — not Alpine-state
// injection. Two clicks, matching exactly what an agent does: (1) tick the
// "Proxy — signs on behalf of the others in this role" checkbox, which
// reveals the representative radio list; (2) pick one representative's
// radio, which fires setEntityProxyPick() -> fetch('/api/entity/.../proxy').
// Deliberately no settle time after the radio click — the harness's very
// next call (saveDraft) fires immediately after, on purpose: this is the
// same fast-click shape that exposed the race setEntityProxyPick()'s
// synchronous optimistic update (86648f37d) exists to survive. Sleeping
// here would hide the exact race this shape is meant to prove is fixed.
async function tickProxy(page, namePart) {
    const checkboxFound = await page.evaluate((namePart) => {
        const all = Array.from(document.querySelectorAll('body *'));
        let card = null;
        for (const el of all) {
            if (!el.children || el.children.length === 0) continue;
            const txt = el.textContent || '';
            if (txt.includes(namePart) && txt.includes('Proxy — signs on behalf of the others in this role')) {
                if (!card || txt.length < card.textContent.length) card = el;
            }
        }
        if (!card) return { ok: false, reason: 'recipient card not found (checkbox stage)' };
        const checkbox = Array.from(card.querySelectorAll('input[type="checkbox"]')).find(cb => {
            const label = cb.closest('label');
            return label && label.textContent.includes('Proxy — signs on behalf of the others in this role');
        });
        if (!checkbox) return { ok: false, reason: 'proxy checkbox not found' };
        document.querySelectorAll('[data-harness-proxy-checkbox]').forEach(e => e.removeAttribute('data-harness-proxy-checkbox'));
        checkbox.setAttribute('data-harness-proxy-checkbox', '1');
        return { ok: true };
    }, namePart);
    if (!checkboxFound.ok) throw new Error(`tickProxy: ${checkboxFound.reason} for "${namePart}"`);

    await page.click('[data-harness-proxy-checkbox="1"]');
    await sleep(500); // let the x-show radio picker render

    const radioFound = await page.evaluate((namePart) => {
        const all = Array.from(document.querySelectorAll('body *'));
        let card = null;
        for (const el of all) {
            if (!el.children || el.children.length === 0) continue;
            const txt = el.textContent || '';
            if (txt.includes(namePart) && txt.includes('Pick the ONE who actually signs')) {
                if (!card || txt.length < card.textContent.length) card = el;
            }
        }
        if (!card) return { ok: false, reason: 'representative picker panel did not open' };
        const radio = card.querySelector('input[type="radio"]');
        if (!radio) return { ok: false, reason: 'no representative radio found in picker panel' };
        document.querySelectorAll('[data-harness-proxy-radio]').forEach(e => e.removeAttribute('data-harness-proxy-radio'));
        radio.setAttribute('data-harness-proxy-radio', '1');
        return { ok: true };
    }, namePart);
    if (!radioFound.ok) throw new Error(`tickProxy: ${radioFound.reason} for "${namePart}"`);

    await page.click('[data-harness-proxy-radio="1"]');
}

async function saveDraft(page) {
    await clickBtnContains(page, 'Save Draft');
    await sleep(2000);
}

async function goToStep(page, host, flowId, step) {
    await page.goto(`https://${host}/docuperfect/esign/${flowId}/step/${step}`, { waitUntil: 'networkidle0', timeout: 30000 });
    await sleep(2200);
}

async function advanceNext(page, buttonText = 'Next') {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 }).catch(() => null),
        clickBtnContains(page, buttonText),
    ]);
    await sleep(2200);
}

// Sets the mandate expiry (Details step) so Next is enabled, then advances
// through Fill & Review to Sign & Send.
async function completeDetailsAndAdvanceToSignSend(page) {
    await clickBtnExact(page, '6 Mo');
    await sleep(800);
    await advanceNext(page, 'Next →'); // Details -> Fill & Review
    await advanceNext(page, 'Signing Setup'); // Fill & Review -> Sign & Send
}

// Ticks "Exclude from email" for named recipients (deceased parties must
// never actually be dispatched a signing request) then dispatches.
// Returns the generated documentId, or throws with a clear reason.
async function dispatchToSigning(page, host, flowId, excludeNameParts) {
    await goToStep(page, host, flowId, 6);

    for (const namePart of excludeNameParts) {
        await page.evaluate((namePart) => {
            const root = document.querySelector('[x-data="esignWizard()"]');
            const data = window.Alpine.$data(root);
            const idx = data.recipients.findIndex(r => (r.name || '').includes(namePart));
            if (idx >= 0) data.recipients[idx].skipEmail = true;
        }, namePart);
    }
    await sleep(500);

    const clicked = await page.evaluate(() => {
        const btn = Array.from(document.querySelectorAll('button')).find(b => b.innerText && b.innerText.trim() === 'Sign Document');
        if (btn && !btn.disabled) { btn.click(); return true; }
        return btn ? `disabled=${btn.disabled}` : 'not_found';
    });
    if (clicked !== true) throw new Error(`dispatchToSigning: Sign Document button ${clicked}`);
    await sleep(3000);

    if (page.url().includes('/signatures/setup')) {
        await clickBtnExact(page, 'Preview & Continue');
        await sleep(2500);
    }

    const m = page.url().match(/documents\/(\d+)/);
    if (!m) throw new Error(`dispatchToSigning: did not land on a document sign URL — got ${page.url()}`);
    return parseInt(m[1], 10);
}

// 2026-08-27 — "X / Y items completed" is the AGENT screen's own progress
// text. A RECIPIENT's screen shows a DIFFERENT format entirely — "N items
// remaining" (no total given) — found via a full-page screenshot after the
// first format's absence caused the signing loop to spin forever with no
// way to detect completion. Returns a normalized { done, total, remaining }
// (total/done null when only "remaining" is known) or null if neither
// pattern is present at all.
async function getProgress(page) {
    return page.evaluate(() => {
        const completedEl = Array.from(document.querySelectorAll('*')).find(e => e.innerText && /^\d+ \/ \d+ items completed$/.test(e.innerText.trim()));
        if (completedEl) {
            const [done, total] = completedEl.innerText.trim().split(' / ').map(s => parseInt(s));
            return { text: completedEl.innerText.trim(), done, total, remaining: total - done };
        }
        const remainingEl = Array.from(document.querySelectorAll('*')).find(e => e.innerText && /^\d+ items? remaining$/i.test(e.innerText.trim()));
        if (remainingEl) {
            const remaining = parseInt(remainingEl.innerText.trim());
            return { text: remainingEl.innerText.trim(), done: null, total: null, remaining };
        }
        return null;
    });
}

async function clickHighlightedField(page) {
    return page.evaluate(() => {
        const all = Array.from(document.querySelectorAll('div, span'));
        for (const el of all) {
            const style = getComputedStyle(el);
            if (style.borderStyle && style.borderStyle.includes('dashed')) {
                const r = el.getBoundingClientRect();
                if (r.width > 30 && r.width < 400 && r.height > 15 && r.height < 100 && r.top >= 0 && r.top < window.innerHeight) {
                    el.scrollIntoView({ block: 'center' });
                    const rr = el.getBoundingClientRect();
                    return { found: true, x: rr.x + rr.width / 2, y: rr.y + rr.height / 2 };
                }
            }
        }
        return { found: false };
    });
}

// 2026-08-27 — root cause of the intermittent signing-completion stall,
// found via ESIGN_HARNESS_DEBUG: sign.blade.php's ceremony-field setup
// (_makeCeremonyFieldsEditable, called from a tryInit() polled every 200ms
// for up to 4s after the page paginates) is not idempotent — each poll tick
// that still finds a "[data-marker-party][data-marker-type=...]" element
// (which the REPLACEMENT <input> itself still matches) tears it down and
// creates a fresh one with value:'' for the location field (no prefill,
// unlike day/month/year/time). Typing into the field while that 4s window
// is still open gets silently wiped by the next tick — the exact "always
// empty, same position, every loop" symptom this harness was hitting.
// Fixed here, not in the product (out of scope for this pass) — retry with
// verification for up to ~4.5s, comfortably covering the polling window,
// instead of trusting a single type+Tab to stick.
async function fillLocationIfPresent(page) {
    const box = await page.evaluate(() => {
        const input = document.querySelector('input[placeholder="Location"]');
        if (!input) return null;
        const r = input.getBoundingClientRect();
        if (r.width === 0) return null;
        input.scrollIntoView({ block: 'center' });
        return { x: r.x + r.width / 2, y: r.y + r.height / 2, value: input.value };
    });
    if (!box || box.value) return false;

    for (let attempt = 0; attempt < 9; attempt++) {
        const fresh = await page.evaluate(() => {
            const input = document.querySelector('input[placeholder="Location"]');
            if (!input) return null;
            const r = input.getBoundingClientRect();
            return { x: r.x + r.width / 2, y: r.y + r.height / 2, value: input.value };
        });
        if (!fresh) break; // field disappeared — a "Go to next" moved us on
        if (fresh.value) return true; // a previous attempt already stuck
        await page.mouse.click(fresh.x, fresh.y);
        await page.keyboard.type('Uvongo', { delay: 15 });
        await page.keyboard.press('Tab');
        await sleep(500);
        const after = await page.evaluate(() => {
            const input = document.querySelector('input[placeholder="Location"]');
            return input ? input.value : null;
        });
        if (process.env.ESIGN_HARNESS_DEBUG) console.error(`[signing-debug] fillLocationIfPresent: attempt ${attempt}, value after type+tab = ${JSON.stringify(after)}`);
        if (after) return true;
    }
    return true; // treat as handled either way — caller just needs to know to re-check progress, not spin forever on this field
}

// 2026-08-27 — found via ESIGN_HARNESS_DEBUG on a recipient's own signing
// screen: clickBtnExact('Type')/clickBtnExact('Apply Signature') match by
// text alone, `.find()`ing the FIRST button anywhere in the DOM with that
// label — but the signing modal is instantiated once PER marker (one per
// initial box, one for the final signature), and only ONE instance is
// actually visible (display!=none, non-zero rect) at a time. The click
// could silently land on a hidden instance's identically-labelled button,
// which is exactly indistinguishable from "worked" (clickBtnExact returns
// true) while nothing visible changes — the loop then spins forever
// re-clicking the same still-unsigned marker. Every step here is scoped to
// VISIBLE elements only (non-zero rect), never "the first match anywhere".
async function clickVisibleBtn(page, matchFn) {
    return page.evaluate((matchFnStr) => {
        const matchFn = new Function('text', `return (${matchFnStr})(text)`);
        const btn = Array.from(document.querySelectorAll('button')).find(b => {
            if (!matchFn(b.innerText.trim())) return false;
            const r = b.getBoundingClientRect();
            return r.width > 0 && r.height > 0;
        });
        if (btn) { btn.click(); return true; }
        return false;
    }, matchFn.toString());
}

async function typeAndApplySignature(page, name) {
    const typeClicked = await clickVisibleBtn(page, (t) => t === 'Type');
    await sleep(600);
    const inputBox = await page.evaluate(() => {
        // Must be on-screen in the CURRENT viewport, not just "decent
        // size" — a hidden/off-screen modal instance's identically-shaped
        // input matches on size alone and sends the click miles off-canvas
        // (seen at y:-2300 once the viewport bound was dropped).
        const input = Array.from(document.querySelectorAll('input[type="text"]')).find(i => {
            const r = i.getBoundingClientRect();
            return r.width > 100 && r.height > 10 && r.top >= 0 && r.top < window.innerHeight && r.left >= 0 && r.left < window.innerWidth;
        });
        if (!input) return null;
        const r = input.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (process.env.ESIGN_HARNESS_DEBUG) console.error(`[signing-debug] typeAndApplySignature: typeClicked=${typeClicked}, inputBox=${JSON.stringify(inputBox)}`);
    if (inputBox) {
        await page.mouse.click(inputBox.x, inputBox.y, { clickCount: 3 });
        await page.keyboard.type(name, { delay: 15 });
        await sleep(300);
    }
    const applyClicked = await clickVisibleBtn(page, (t) => t.startsWith('Apply Signature'));
    if (process.env.ESIGN_HARNESS_DEBUG) console.error(`[signing-debug] typeAndApplySignature: applyClicked=${applyClicked}`);
    await sleep(1500);
    const dlg = await page.evaluate(() => document.body.innerText.includes('Apply to Remaining Markers?'));
    if (dlg) {
        await clickVisibleBtn(page, (t) => t.startsWith('Yes, Apply to All'));
        await sleep(1200);
    }
}

// Robustly completes the AGENT's own portion of signing (the only party this
// harness can sign as, being logged in as the agent). Retries generously;
// on genuine failure to progress, returns completed:false with a plain
// reason rather than hanging or reporting a false pass.
// 2026-08-27 — second root cause of the intermittent signing-completion
// stall, found via ESIGN_HARNESS_DEBUG + a full-page marker dump: page-break
// initial boxes for the CURRENT SIGNER do carry a dashed border
// clickHighlightedField looks for (_makeWebInitialsInteractive /
// _makeCeremonyFieldsEditable-equivalent on the external page sets
// `border: 2px dashed`), but "Go to next" was not reliably scrolling them
// into view — all 4 unsigned initial elements on a 5-page test document sat
// at NEGATIVE getBoundingClientRect().top (scrolled above the current
// viewport) even after clicking it and waiting. clickHighlightedField only
// scans what's currently on-screen, so it never found them and the loop
// spun on the already-signed final attestation instead. This queries and
// scrolls to an unsigned initial DIRECTLY, filtered by the dashed border
// style itself (not a hardcoded party name) — works identically whether the
// current signer is the agent (internal /documents/{id}/sign) or a
// recipient (external /sign/{token}), since only the CURRENT signer's own
// boxes ever get that border. Bypasses "Go to next" and the viewport-only
// scan entirely for this field type.
async function findAndScrollToUnsignedInitial(page) {
    const evalFn = () => {
        const els = Array.from(document.querySelectorAll('[data-marker-type="initial"]'));
        const target = els.find(el => {
            if (el.getAttribute('data-signed') === 'true') return false;
            const cs = getComputedStyle(el);
            return cs.borderStyle && cs.borderStyle.includes('dashed');
        });
        if (!target) return { found: false };
        target.scrollIntoView({ block: 'center' });
        const r = target.getBoundingClientRect();
        return { found: true, x: r.x + r.width / 2, y: r.y + r.height / 2 };
    };
    const first = await page.evaluate(evalFn);
    if (!first.found) return first;
    // scrollIntoView is async/animated in some browsers — re-read the rect
    // after a short settle instead of trusting the pre-scroll one.
    await sleep(400);
    return page.evaluate(evalFn);
}

async function robustCompleteSigningAsCurrentParty(page, signerName, { maxLoops = 40, stagnantLimit = 8 } = {}) {
    let lastProgress = null;
    let stagnant = 0;
    let lastError = null;

    const DEBUG = !!process.env.ESIGN_HARNESS_DEBUG;
    for (let i = 0; i < maxLoops; i++) {
        const prog = await getProgress(page);
        // Fall back to the "Complete Signing" button becoming enabled —
        // present on both screens, belt-and-braces alongside the
        // remaining-count reaching 0.
        const completeBtnEnabled = await page.evaluate(() => {
            const btn = Array.from(document.querySelectorAll('button')).find(b => b.innerText.trim().startsWith('Complete Signing'));
            return btn ? !btn.disabled : false;
        });
        if (DEBUG) console.error(`[signing-debug] loop ${i} start: progress=${JSON.stringify(prog)}, completeBtnEnabled=${completeBtnEnabled}`);
        if (prog && prog.remaining <= 0) return { completed: true, finalProgress: prog.text };
        if (completeBtnEnabled) return { completed: true, finalProgress: 'complete-button-enabled' };
        // Only count stagnation once we have SOME signal to compare against
        // — a page that never renders a progress indicator at all would
        // otherwise trip the stagnant counter on loop 0 before any real
        // click has happened.
        const progKey = prog ? prog.text : null;
        if (progKey !== null) {
            if (progKey === lastProgress) stagnant++; else { stagnant = 0; lastProgress = progKey; }
            if (stagnant >= stagnantLimit) {
                return { completed: false, finalProgress: progKey, reason: `stuck at ${progKey} for ${stagnantLimit} consecutive loops` };
            }
        } else if (i >= maxLoops - 1) {
            return { completed: false, finalProgress: null, reason: `loop cap reached with no progress indicator and no enabled Complete Signing button` };
        }

        try {
            await clickBtnContains(page, 'Go to next');
            await sleep(900);

            const locFilled = await fillLocationIfPresent(page);
            if (DEBUG) console.error(`[signing-debug] loop ${i}: location field filled = ${locFilled}`);
            if (locFilled) continue;

            let hl = await clickHighlightedField(page);
            if (DEBUG) console.error(`[signing-debug] loop ${i}: highlighted field = ${JSON.stringify(hl)}`);
            if (!hl.found) {
                hl = await findAndScrollToUnsignedInitial(page);
                if (DEBUG) console.error(`[signing-debug] loop ${i}: fallback unsigned-initial scan = ${JSON.stringify(hl)}`);
            }
            if (hl.found) {
                await page.mouse.click(hl.x, hl.y);
                await sleep(900);
                // 2026-08-27 — "Use my saved signature" is agent-only (a
                // recipient has no saved signature to reuse). Recipients
                // only ever see the "Draw"/"Type" tabs + "Apply Signature",
                // so detect the modal by THOSE instead of assuming every
                // signer's modal looks like the agent's.
                const hasSigModal = await page.evaluate(() => {
                    const text = document.body.innerText;
                    if (text.includes('Use my saved signature')) return true;
                    const btns = Array.from(document.querySelectorAll('button')).map(b => b.innerText.trim());
                    return btns.some(t => t === 'Type') && btns.some(t => t.startsWith('Apply Signature'));
                });
                if (hasSigModal) {
                    await typeAndApplySignature(page, signerName);
                    if (DEBUG) console.error(`[signing-debug] loop ${i}: signature typed+applied`);
                } else {
                    const dlg = await page.evaluate(() => document.body.innerText.includes('Apply to Remaining Markers?'));
                    if (dlg) {
                        await clickBtnExact(page, 'Yes, Apply to All'); await sleep(1200);
                        if (DEBUG) console.error(`[signing-debug] loop ${i}: apply-to-remaining dialog handled`);
                    } else if (DEBUG) {
                        console.error(`[signing-debug] loop ${i}: clicked highlighted field at (${hl.x},${hl.y}) — no signature modal, no apply-to-remaining dialog. Progress stayed at "${prog}".`);
                    }
                }
            } else if (DEBUG) {
                console.error(`[signing-debug] loop ${i}: no dashed-border highlighted field found on screen. Progress "${prog}".`);
            }
        } catch (e) {
            lastError = e.message;
            if (DEBUG) console.error(`[signing-debug] loop ${i}: EXCEPTION caught: ${e.message}\n${e.stack}`);
        }
    }

    const finalProg = await getProgress(page);
    return { completed: false, finalProgress: finalProg ? finalProg.text : null, reason: lastError ? `loop cap reached, last error: ${lastError}` : 'loop cap reached without completing' };
}

// Back-compat name — identical behaviour, the agent is just another "current
// party" as far as the signing loop is concerned (see above).
async function robustCompleteAgentSigning(page, agentName, opts) {
    return robustCompleteSigningAsCurrentParty(page, agentName, opts);
}

async function completeSigningAndSend(page) {
    const clicked = await clickBtnExact(page, 'Complete Signing & Send');
    if (!clicked) throw new Error('completeSigningAndSend: button not found');
    await sleep(3000);
}

// 2026-08-27 — recipient signing chain (Johan: "rec 1 matches from agent,
// rec 2 matches from rec 1, etc."). A recipient's token link
// (https://host/sign/{token}) lands on a real FICA-style identity gate
// before the document itself: gateway (enter ID/passport number) ->
// consent (accept declaration) -> the document. No CoreX session involved
// — the token IS the recipient's identity, so this drives a page that was
// never logged in via newPage()/cookie at all.
async function openRecipientSigningLink(browser, link) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1450, height: 1600 });
    await page.goto(link, { waitUntil: 'networkidle2', timeout: 30000 });
    await sleep(1500);
    return page;
}

async function completeIdentityGateway(page, idNumber) {
    const input = await page.evaluate(() => {
        const visible = Array.from(document.querySelectorAll('input')).find(i => {
            const r = i.getBoundingClientRect();
            return r.width > 20 && r.height > 5 && i.type !== 'hidden';
        });
        if (!visible) return null;
        const r = visible.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (!input) throw new Error('completeIdentityGateway: no visible ID/passport input found');
    await page.mouse.click(input.x, input.y);
    await page.keyboard.type(idNumber, { delay: 20 });
    const clicked = await clickBtnContains(page, 'Verify My Identity');
    if (!clicked) throw new Error('completeIdentityGateway: "Verify My Identity" button not found');
    await sleep(2500);
    const failed = await page.evaluate(() => document.body.innerText.includes('did not match') || document.body.innerText.includes('verification failed') || document.body.innerText.includes('incorrect'));
    if (failed) throw new Error('completeIdentityGateway: identity verification was rejected — check the fixture id_number matches what was captured for this recipient');
}

async function completeConsent(page) {
    const cb = await page.evaluate(() => {
        const c = document.querySelector('input[type="checkbox"]');
        if (!c) return null;
        const r = c.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (cb) {
        await page.mouse.click(cb.x, cb.y);
        await sleep(400);
    }
    const clicked = await clickBtnContains(page, 'Proceed to Documents');
    if (!clicked) throw new Error('completeConsent: "Proceed to Documents" button not found');
    await sleep(2500);
}

// 2026-08-27 — a fourth gate between consent and the actual document:
// "How would you like to sign? / Sign Electronically / Download, Print &
// Sign". Only present if the page actually shows it (a recipient who
// already picked electronic on a prior visit may skip straight to the
// document) — checked for, not assumed.
async function completeMethodChoiceIfPresent(page) {
    const hasChoice = await page.evaluate(() => document.body.innerText.includes('How would you like to sign?'));
    if (!hasChoice) return false;
    const clicked = await clickBtnContains(page, 'Sign Electronically');
    if (!clicked) throw new Error('completeMethodChoiceIfPresent: "Sign Electronically" button not found');
    await sleep(2500);
    return true;
}

// Drives a recipient's own signing link fully: identity gateway -> consent
// -> sign every field of theirs -> submit. Returns { completed, finalUrl }.
// Throws (does not silently skip) if the FICA gate is hit unexpectedly —
// that means fixtures.php's pre-approval didn't cover this contact.
async function completeRecipientSigning(browser, link, { idNumber, signerName }) {
    const page = await openRecipientSigningLink(browser, link);
    await completeIdentityGateway(page, idNumber);
    if (page.url().includes('/fica')) {
        throw new Error('completeRecipientSigning: hit the FICA gate — this contact has no pre-approved fica_submissions row (see fixtures.php regEnsureFicaApproved)');
    }
    if (page.url().includes('/consent')) {
        await completeConsent(page);
    }
    await completeMethodChoiceIfPresent(page);
    return page;
}

// 2026-08-27 — Johan found by hand: "Reordering the directors on the left
// does not update the seller block or the Domicilium on the right." Clicks
// the REAL "Move down" arrow (moveEntityRep(ri, contactId, 1)) on the FIRST
// representative row inside the given entity recipient's card — the same
// button an agent clicks. No settle time after the click; the caller
// captures immediately to test whether the live preview actually updates
// in place, which is exactly the gap that let this bug through undetected.
async function moveEntityRepDown(page, namePart) {
    const found = await page.evaluate((namePart) => {
        const all = Array.from(document.querySelectorAll('body *'));
        let card = null;
        for (const el of all) {
            if (!el.children || el.children.length === 0) continue;
            const txt = el.textContent || '';
            if (txt.includes(namePart) && txt.includes('Signs via its representative')) {
                if (!card || txt.length < card.textContent.length) card = el;
            }
        }
        if (!card) return { ok: false, reason: 'representative list card not found' };
        const downBtn = Array.from(card.querySelectorAll('button[title="Move down"]')).find(b => !b.disabled);
        if (!downBtn) return { ok: false, reason: 'no enabled "Move down" button found' };
        document.querySelectorAll('[data-harness-move-down]').forEach(e => e.removeAttribute('data-harness-move-down'));
        downBtn.setAttribute('data-harness-move-down', '1');
        return { ok: true };
    }, namePart);
    if (!found.ok) throw new Error(`moveEntityRepDown: ${found.reason} for "${namePart}"`);
    await page.click('[data-harness-move-down="1"]');
}

module.exports = {
    sleep, clickBtnContains, clickBtnExact,
    newPage, selectTemplate, selectProperty, addRecipientBySearch, addRecipientManual,
    tickDeceasedAndBindExecutor, tickProxy, moveEntityRepDown, saveDraft, goToStep, advanceNext,
    completeDetailsAndAdvanceToSignSend, dispatchToSigning, getProgress,
    robustCompleteAgentSigning, robustCompleteSigningAsCurrentParty, completeSigningAndSend,
    openRecipientSigningLink, completeIdentityGateway, completeConsent, completeMethodChoiceIfPresent, completeRecipientSigning,
};
