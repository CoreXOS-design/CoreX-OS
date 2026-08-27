#!/usr/bin/env node
// E-SIGN REGRESSION HARNESS — one command, drives every flow shape end to
// end on QA1, snapshots the document body at EVERY link in Johan's chain
// (document selected -> property -> recipients -> details -> fill & review
// -> sign & send -> preview -> agent signing), and diffs each link against
// the one before it. Reports the exact link where the chain first breaks.
//
// USAGE (from the QA1 repo root):
//   node scripts/esign/regression/run.js
//   node scripts/esign/regression/run.js --shape=D
//   node scripts/esign/regression/run.js --shape=B,C,D
//
// Requires: the QA1 app running at https://qatesting1.corexos.co.za, and
// this box's `php artisan tinker` able to reach it.
//
// Read-only against the product: every stage capture reads the real
// rendered screen (innerText of the live preview / signing-screen DOM),
// never an internal API response. Test-data setup (fixtures.php) uses
// direct DB writes — see that file's own header for why that's a different
// question from the flow being regression-tested.

const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');
const puppeteer = require('/corex-qa1/node_modules/puppeteer');

const REPO_ROOT = '/corex-qa1';
const HOST = 'qatesting1.corexos.co.za';
const REPORT_DIR = path.join(__dirname, 'reports');
const PROPERTY_SEARCH = 'Regression Harness Way';
const PROPERTY_MATCH = 'Regression Harness Way';
const TEMPLATE_BUTTON = 'EXCLUSIVE AUTHORITY TO SELL';

const { mintSessionCookie } = require('./lib/cookie');
const driver = require('./lib/driver');
const capture = require('./lib/capture');
const { shapeList } = require('./shapes');
const { runAssertions } = require('./lib/assertions');

function nowStamp() {
    return new Date().toISOString().replace(/[:.]/g, '-');
}

async function ensureFixtures() {
    execSync(`php artisan tinker --execute="require '${path.join(__dirname, 'fixtures.php')}';"`, {
        cwd: REPO_ROOT, encoding: 'utf8', timeout: 60000,
    });
    return JSON.parse(fs.readFileSync(path.join(__dirname, '.fixtures.json'), 'utf8'));
}

function buildFixtureTruth() {
    return {
        'RegSellerOne': { tel: '0821000001', email: 'reg.seller.one@harness.test' },
        'RegSellerTwo': { tel: '0821000002', email: 'reg.seller.two@harness.test' },
        'RegDeceased': { tel: '0821000003', email: 'reg.deceased@harness.test' },
        'RegExecutor': { tel: '0821000004', email: 'reg.executor@harness.test' },
        'RegSupplierExecutor': { tel: '0821000005', email: 'reg.supplier.executor@harness.test' },
        'RegDirectorOne': { tel: '0821000010', email: 'regdirectorone@harness.test' },
        'RegDirectorTwo': { tel: '0821000011', email: 'regdirectortwo@harness.test' },
        'RegDirectorThree': { tel: '0821000012', email: 'regdirectorthree@harness.test' },
        'RegManualEntry': { tel: '0821000099', email: 'reg.manual.entry@harness.test' },
    };
}

async function captureTextStage(page, name) {
    const text = await capture.extractPreviewText(page);
    return {
        name,
        domicilium: capture.parseDomicilium(text),
        clause: capture.parseClauseOpening(text),
        signatureSummary: capture.parseSignatureBlockSummary(text),
    };
}

async function runShape(browser, cookie, shapeDef, fixtureTruth, warnings) {
    const page = await driver.newPage(browser, HOST, cookie);
    const stages = [];
    let notes = [];

    try {
        // Link 1: Template.
        await driver.selectTemplate(page, HOST, TEMPLATE_BUTTON);
        stages.push(await captureTextStage(page, 'Template'));

        // Link 2: Property.
        const flowId = await driver.selectProperty(page, PROPERTY_SEARCH, PROPERTY_MATCH);
        stages.push(await captureTextStage(page, 'Property'));

        // Link 3: Recipients (shape-specific build). The in-session live
        // preview pane does not repaint after a plain recipient add (only a
        // Replace-this-party Confirm forces a refresh, per c38c50b7e/
        // 42cf6ae54) — a fresh navigation to the same step is what a real
        // agent effectively gets by leaving and coming back, and is the only
        // way to capture what's actually SAVED rather than a stale in-memory
        // preview. Noted as its own (separate, low-severity) finding, not
        // conflated with the chain-break checks this harness exists for.
        const { expected } = await shapeDef.build(page, warnings);
        const flowIdForReload = await page.evaluate(() => {
            const root = document.querySelector('[x-data="esignWizard()"]');
            return root && window.Alpine ? window.Alpine.$data(root).flowId : null;
        });
        await driver.goToStep(page, HOST, flowIdForReload, 3);
        stages.push(await captureTextStage(page, 'Recipients'));

        // Link 4: Details.
        await driver.goToStep(page, HOST, flowId, 4);
        await driver.clickBtnExact(page, '6 Mo');
        await driver.sleep(800);
        stages.push(await captureTextStage(page, 'Details'));

        // Link 5: Fill & Review (the hard stop — no manual edits in this run).
        await driver.advanceNext(page, 'Next →');
        stages.push(await captureTextStage(page, 'Fill & Review'));

        // Link 6: Sign & Send ("next" — Johan's chain treats the click-through
        // from Fill & Review as landing directly here; there is no distinct
        // intermediate screen in the real UI to capture separately).
        await driver.advanceNext(page, 'Signing Setup');
        const signSendText = await capture.extractPreviewText(page);
        stages.push({
            name: 'Sign & Send',
            domicilium: capture.parseDomicilium(signSendText),
            clause: capture.parseClauseOpening(signSendText),
            signatureSummary: capture.parseSignatureBlockSummary(signSendText),
            signingOrderCount: capture.parseSigningOrderCount(await page.evaluate(() => document.body.innerText)),
        });

        // Link 7: Preview (the markers/zones screen between Sign & Send and
        // the live signing screen) + Link 8: Agent Signing.
        const deceasedNames = expected.deceasedNames || [];
        let documentId = null;
        try {
            documentId = await dispatchCapturingPreview(page, HOST, flowId, deceasedNames, stages);
        } catch (e) {
            notes.push(`Could not reach Preview/signing: ${e.message}`);
        }

        if (documentId) {
            await driver.sleep(2000);
            let signPageText = await capture.extractPreviewText(page);
            if (!signPageText) { await driver.sleep(2500); signPageText = await capture.extractPreviewText(page); }
            const signPageBlocks = await capture.captureSignPageBlocks(page);
            stages.push({
                name: 'Agent Signing Screen',
                domicilium: capture.parseDomicilium(signPageText),
                clause: capture.parseClauseOpening(signPageText),
                signatureSummary: capture.parseSignatureBlockSummary(signPageText),
                signPageBlocks,
            });

            const agentResult = await driver.robustCompleteAgentSigning(page, 'Johan Reichel');
            if (!agentResult.completed) {
                notes.push(`Agent signing did not complete: ${agentResult.reason} (progress: ${agentResult.finalProgress}). Recipient-by-recipient signing chain (rec 1, rec 2, ...) COULD NOT BE CHECKED this run — needs the agent's own signature to complete first, then each recipient's own signing link (Mailpit) in turn; out of scope for this pass under the deadline.`);
            } else {
                try {
                    await driver.completeSigningAndSend(page);
                    notes.push(`Agent signing completed and dispatched (document #${documentId}). Recipient-by-recipient chain (rec 1, rec 2, ...) COULD NOT BE CHECKED this run — would require opening each recipient's own signing link from Mailpit and signing as them in turn; not built in this pass under the deadline.`);
                } catch (e) {
                    notes.push(`Could not click Complete Signing & Send: ${e.message}`);
                }
            }
        } else {
            notes.push('Preview/Agent Signing Screen COULD NOT BE CHECKED this run.');
        }

        const assertions = runAssertions(stages, expected, fixtureTruth);
        await page.close();
        return { key: shapeDef.key, label: shapeDef.label, ok: true, stages, expected, assertions, notes, documentId, flowId };
    } catch (e) {
        await page.close().catch(() => {});
        return { key: shapeDef.key, label: shapeDef.label, ok: false, error: e.message, stack: e.stack, stages, notes, assertions: null };
    }
}

// Dispatches to signing and captures the Preview (markers/zones) screen on
// the way through, before landing on the live Agent Signing screen.
async function dispatchCapturingPreview(page, host, flowId, excludeNameParts, stages) {
    await driver.goToStep(page, host, flowId, 6);
    for (const namePart of excludeNameParts) {
        await page.evaluate((namePart) => {
            const root = document.querySelector('[x-data="esignWizard()"]');
            const data = window.Alpine.$data(root);
            const idx = data.recipients.findIndex(r => (r.name || '').includes(namePart));
            if (idx >= 0) data.recipients[idx].skipEmail = true;
        }, namePart);
    }
    await driver.sleep(500);

    const clicked = await page.evaluate(() => {
        const btn = Array.from(document.querySelectorAll('button')).find(b => b.innerText && b.innerText.trim() === 'Sign Document');
        if (btn && !btn.disabled) { btn.click(); return true; }
        return btn ? `disabled=${btn.disabled}` : 'not_found';
    });
    if (clicked !== true) throw new Error(`Sign Document button ${clicked}`);
    await driver.sleep(3000);

    if (page.url().includes('/signatures/setup')) {
        // This IS "Preview" — capture it before continuing.
        await driver.sleep(1500);
        const previewText = await capture.extractPreviewText(page);
        stages.push({
            name: 'Preview',
            domicilium: capture.parseDomicilium(previewText),
            clause: capture.parseClauseOpening(previewText),
            signatureSummary: capture.parseSignatureBlockSummary(previewText),
        });
        await driver.clickBtnExact(page, 'Preview & Continue');
        await driver.sleep(2500);
    } else {
        stages.push({ name: 'Preview', domicilium: { found: false, entries: [] }, clause: { found: false, text: '' }, signatureSummary: { count: 0, blocks: [] } });
    }

    const m = page.url().match(/documents\/(\d+)/);
    if (!m) throw new Error(`did not land on a document sign URL — got ${page.url()}`);
    return parseInt(m[1], 10);
}

function printReport(results) {
    console.log('\n' + '='.repeat(78));
    console.log('E-SIGN REGRESSION HARNESS — RESULTS');
    console.log('='.repeat(78));

    for (const r of results) {
        console.log(`\nSHAPE ${r.key} — ${r.label}`);
        if (!r.ok) {
            console.log(`  COULD NOT COMPLETE — ${r.error}`);
            continue;
        }
        const master = r.assertions['0_MASTER_chain_holds_link_by_link'];
        const mv = master.pass === true ? 'PASS' : (master.pass === false ? 'FAIL' : 'INCOMPLETE');
        console.log(`  [${mv}] CHAIN — ${master.detail}`);
        if (master.chain) {
            master.chain.forEach(l => console.log(`      ${l.link}: ${l.result}`));
        }
        for (const [key, res] of Object.entries(r.assertions)) {
            if (key === '0_MASTER_chain_holds_link_by_link') continue;
            const verdict = res.pass === true ? 'PASS' : (res.pass === false ? 'FAIL' : 'INCOMPLETE');
            const stageNote = res.firstDivergentStage ? ` (first wrong at: ${res.firstDivergentStage})` : '';
            console.log(`  [${verdict}] ${key}${stageNote} — ${res.detail}`);
        }
        if (r.notes.length) {
            console.log('  Notes:');
            r.notes.forEach(n => console.log(`    - ${n}`));
        }
    }
    console.log('\n' + '='.repeat(78));
    const failCount = results.filter(r => r.ok && Object.values(r.assertions).some(a => a.pass === false)).length;
    const incompleteCount = results.filter(r => !r.ok || Object.values(r.assertions || {}).some(a => a.pass === null)).length;
    console.log(`${results.length} shapes run. ${failCount} with at least one FAIL. ${incompleteCount} with something INCOMPLETE.`);
    console.log('='.repeat(78) + '\n');
}

async function main() {
    const args = process.argv.slice(2);
    const shapeArg = args.find(a => a.startsWith('--shape='));
    const wantedShapes = shapeArg ? shapeArg.replace('--shape=', '').split(',') : null;

    console.log('Minting QA1 session...');
    const cookie = mintSessionCookie(REPO_ROOT);

    console.log('Ensuring disposable fixtures (idempotent)...');
    const fixtures = await ensureFixtures();
    const fixtureTruth = buildFixtureTruth();

    const shapes = shapeList(fixtures).filter(s => !wantedShapes || wantedShapes.includes(s.key));
    console.log(`Running ${shapes.length} shape(s): ${shapes.map(s => s.key).join(', ')}`);

    const browser = await puppeteer.launch({
        executablePath: '/usr/bin/chromium', headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    const results = [];
    for (const shapeDef of shapes) {
        console.log(`\n--- Shape ${shapeDef.key}: ${shapeDef.label} ---`);
        const warnings = [];
        const result = await runShape(browser, cookie, shapeDef, fixtureTruth, warnings);
        result.warnings = warnings;
        results.push(result);
        console.log(`Shape ${shapeDef.key} done.`);
    }

    await browser.close();
    printReport(results);

    if (!fs.existsSync(REPORT_DIR)) fs.mkdirSync(REPORT_DIR, { recursive: true });
    const reportFile = path.join(REPORT_DIR, `run-${nowStamp()}.json`);
    fs.writeFileSync(reportFile, JSON.stringify(results, null, 2));
    console.log(`Full report written to: ${reportFile}`);
}

main().catch(e => { console.error('HARNESS ERROR:', e); process.exit(1); });
