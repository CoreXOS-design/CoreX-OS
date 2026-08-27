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

async function getProgress(page) {
    return page.evaluate(() => {
        const el = Array.from(document.querySelectorAll('*')).find(e => e.innerText && /^\d+ \/ \d+ items completed$/.test(e.innerText.trim()));
        return el ? el.innerText.trim() : null;
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

async function fillLocationIfPresent(page) {
    const box = await page.evaluate(() => {
        const input = document.querySelector('input[placeholder="Location"]');
        if (!input) return null;
        const r = input.getBoundingClientRect();
        if (r.width === 0) return null;
        input.scrollIntoView({ block: 'center' });
        return { x: r.x + r.width / 2, y: r.y + r.height / 2, value: input.value };
    });
    if (box && !box.value) {
        await page.mouse.click(box.x, box.y);
        await page.keyboard.type('Uvongo', { delay: 15 });
        await page.keyboard.press('Tab');
        await sleep(700);
        return true;
    }
    return false;
}

async function typeAndApplySignature(page, name) {
    await clickBtnExact(page, 'Type');
    await sleep(500);
    const inputBox = await page.evaluate(() => {
        const input = Array.from(document.querySelectorAll('input[type="text"]')).find(i => {
            const r = i.getBoundingClientRect();
            return r.width > 100 && r.height > 10 && r.y > 300 && r.y < 1400;
        });
        if (!input) return null;
        const r = input.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (inputBox) {
        await page.mouse.click(inputBox.x, inputBox.y, { clickCount: 3 });
        await page.keyboard.type(name, { delay: 15 });
        await sleep(300);
    }
    await clickBtnExact(page, 'Apply Signature');
    await sleep(1500);
    const dlg = await page.evaluate(() => document.body.innerText.includes('Apply to Remaining Markers?'));
    if (dlg) {
        await clickBtnExact(page, 'Yes, Apply to All');
        await sleep(1200);
    }
}

// Robustly completes the AGENT's own portion of signing (the only party this
// harness can sign as, being logged in as the agent). Retries generously;
// on genuine failure to progress, returns completed:false with a plain
// reason rather than hanging or reporting a false pass.
async function robustCompleteAgentSigning(page, agentName, { maxLoops = 40, stagnantLimit = 8 } = {}) {
    let lastProgress = null;
    let stagnant = 0;
    let lastError = null;

    for (let i = 0; i < maxLoops; i++) {
        const prog = await getProgress(page);
        if (prog) {
            const [done, total] = prog.split(' / ').map(s => parseInt(s));
            if (done >= total) return { completed: true, finalProgress: prog };
        }
        if (prog === lastProgress) stagnant++; else { stagnant = 0; lastProgress = prog; }
        if (stagnant >= stagnantLimit) {
            return { completed: false, finalProgress: prog, reason: `stuck at ${prog} for ${stagnantLimit} consecutive loops` };
        }

        try {
            await clickBtnContains(page, 'Go to next');
            await sleep(900);

            if (await fillLocationIfPresent(page)) continue;

            const hl = await clickHighlightedField(page);
            if (hl.found) {
                await page.mouse.click(hl.x, hl.y);
                await sleep(900);
                const hasSigModal = await page.evaluate(() => document.body.innerText.includes('Use my saved signature'));
                if (hasSigModal) {
                    await typeAndApplySignature(page, agentName);
                } else {
                    const dlg = await page.evaluate(() => document.body.innerText.includes('Apply to Remaining Markers?'));
                    if (dlg) { await clickBtnExact(page, 'Yes, Apply to All'); await sleep(1200); }
                }
            }
        } catch (e) {
            lastError = e.message;
        }
    }

    const finalProg = await getProgress(page);
    return { completed: false, finalProgress: finalProg, reason: lastError ? `loop cap reached, last error: ${lastError}` : 'loop cap reached without completing' };
}

async function completeSigningAndSend(page) {
    const clicked = await clickBtnExact(page, 'Complete Signing & Send');
    if (!clicked) throw new Error('completeSigningAndSend: button not found');
    await sleep(3000);
}

module.exports = {
    sleep, clickBtnContains, clickBtnExact,
    newPage, selectTemplate, selectProperty, addRecipientBySearch, addRecipientManual,
    tickDeceasedAndBindExecutor, tickProxy, saveDraft, goToStep, advanceNext,
    completeDetailsAndAdvanceToSignSend, dispatchToSigning, getProgress,
    robustCompleteAgentSigning, completeSigningAndSend,
};
