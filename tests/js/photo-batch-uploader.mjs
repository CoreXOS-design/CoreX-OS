// AT-436 class guard — the shared photo batch uploader must never silently drop a photo.
// Loads the ACTUAL shipped uploader (public/js/corex-photo-batch-uploader.js) into a minimal
// browser sandbox (tests/js convention: no runner, node vm) and pins:
//   1. one oversize file does NOT swallow the other files picked with it
//   2. every oversize file gets its own visible failed row
//   3. a throwing onBatchDone never wedges the queue (later batches still upload)
//   4. an aborted request ends as a visible failed row, not "Uploading…" forever
//   5. leaving the page while a photo is uploading / failed is warned about; all-done is not
//
// Run:  node tests/js/photo-batch-uploader.mjs      (exit 0 = pass, 1 = fail)
// PHPUnit wrapper: tests/Feature/RentalInspections/RentalInspectionPhotoSafetyTest.php

import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const code = fs.readFileSync(process.env.UPLOADER_JS || path.join(root, 'public/js/corex-photo-batch-uploader.js'), 'utf8');

let fails = 0;
const ok = (cond, msg) => { console.log((cond ? 'PASS ' : 'FAIL ') + msg); if (!cond) fails++; };

const MB = 1024 * 1024;
const file = (name, size = 1000) => ({ name, size });

// Each fresh sandbox = a fresh page load. `mode` decides what the fake server does to a request.
function makeSandbox() {
  const listeners = {};
  const sent = [];
  const state = { mode: () => 'ok' };

  class FakeXHR {
    constructor() { this.upload = {}; this.headers = {}; }
    open(method, url) { this.method = method; this.url = url; }
    setRequestHeader(k, v) { this.headers[k] = v; }
    send(fd) {
      sent.push(fd);
      const mode = state.mode(sent.length);
      setImmediate(() => {
        if (mode === 'abort') { this.onabort && this.onabort(); return; }
        if (mode === 'network') { this.onerror && this.onerror(); return; }
        if (mode === 'fail422') {
          this.status = 422; this.responseText = JSON.stringify({ message: 'nope' });
          this.onload && this.onload(); return;
        }
        this.status = 201;
        const n = (fd.getAll('photos[]') || []).length;
        this.responseText = JSON.stringify({
          photos: Array.from({ length: n }, (_, i) => ({ id: sent.length * 100 + i })),
          observation: null,
        });
        this.onload && this.onload();
      });
    }
  }
  class FakeFormData {
    constructor() { this.items = []; }
    append(k, v) { this.items.push([k, v]); }
    getAll(k) { return this.items.filter(([key]) => key === k).map(([, v]) => v); }
  }

  const quiet = { error() {}, log() {}, warn() {} }; // the throwing-callback case logs on purpose
  const window = {
    console: quiet,
    addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
  };
  const sandbox = { window, XMLHttpRequest: FakeXHR, FormData: FakeFormData, crypto: { randomUUID: () => 'u-' + Math.random() }, console: quiet, setImmediate };
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);

  const uploader = () => window.corexPhotoBatchUploader({ csrf: 't', uploadUrl: '/up', photos: [] });
  const leave = () => {
    const ev = { prevented: false, returnValue: undefined, preventDefault() { this.prevented = true; } };
    (listeners.beforeunload || []).forEach(fn => fn(ev));
    return ev.prevented;
  };
  return { window, uploader, state, sent, leave };
}

// ── 1 + 2: oversize does not swallow the rest ──
{
  const s = makeSandbox();
  const u = s.uploader();
  await u.uploadFiles([file('a.jpg'), file('huge1.jpg', 51 * MB), file('b.jpg'), file('huge2.jpg', 60 * MB)], {});
  const failed = u.uploadBatches.filter(b => b.status === 'failed');
  const done = u.uploadBatches.filter(b => b.status === 'done');
  ok(done.length === 1 && done[0].files.map(f => f.name).join() === 'a.jpg,b.jpg', 'oversize in the selection: the two normal photos still upload');
  ok(failed.length === 2 && failed.map(b => b.files[0].name).sort().join() === 'huge1.jpg,huge2.jpg', 'oversize in the selection: EACH oversize file gets its own failed row');
  ok(failed.every(b => /50MB/.test(b.error)), 'oversize failed rows say why');
  ok(u.photos.length === 2, 'the two normal photos are on the server (photos array)');
}

// ── 1b: ONLY oversize files -> nothing sent, all reported ──
{
  const s = makeSandbox();
  const u = s.uploader();
  await u.uploadFiles([file('huge.jpg', 70 * MB)], {});
  ok(s.sent.length === 0 && u.uploadBatches.length === 1 && u.uploadBatches[0].status === 'failed', 'only-oversize selection: nothing sent, one visible failed row');
}

// ── 3: a throwing onBatchDone must not wedge the queue ──
{
  const s = makeSandbox();
  const u = s.uploader();
  // 11 small files = 2 batches (max 10 per request)
  const many = Array.from({ length: 11 }, (_, i) => file('p' + i + '.jpg'));
  let calls = 0;
  await Promise.race([
    u.uploadFiles(many, {}, () => { calls++; throw new Error('boom'); }),
    new Promise((_, rej) => setTimeout(() => rej(new Error('uploadFiles hung')), 2000)),
  ]).then(() => {
    ok(s.sent.length === 2, 'throwing onBatchDone: BOTH batches were sent (queue not wedged)');
    ok(u.uploadBatches.every(b => b.status === 'done'), 'throwing onBatchDone: both batches end done (photos were saved)');
    ok(calls === 2, 'throwing onBatchDone: callback ran for each batch');
    ok(u.uploadBusy === false, 'throwing onBatchDone: uploadBusy is released');
  }).catch(e => ok(false, 'throwing onBatchDone: ' + e.message));
}

// ── 4: abort / network error end as a visible failed row ──
for (const mode of ['abort', 'network', 'fail422']) {
  const s = makeSandbox();
  s.state.mode = () => mode;
  const u = s.uploader();
  await Promise.race([
    u.uploadFiles([file('a.jpg')], {}),
    new Promise((_, rej) => setTimeout(() => rej(new Error('hung')), 2000)),
  ]).then(() => {
    ok(u.uploadBatches[0].status === 'failed' && !!u.uploadBatches[0].error, `${mode}: ends as a failed row with a message`);
    ok(u.uploadBusy === false, `${mode}: uploadBusy released`);
  }).catch(e => ok(false, `${mode}: ${e.message}`));
}

// ── 4b: a failed batch retries the SAME files and can succeed ──
{
  const s = makeSandbox();
  s.state.mode = (n) => (n === 1 ? 'network' : 'ok');
  const u = s.uploader();
  await u.uploadFiles([file('a.jpg')], {});
  ok(u.uploadBatches[0].status === 'failed', 'retry: first attempt failed');
  await u.retryBatch(u.uploadBatches[0]);
  ok(u.uploadBatches[0].status === 'done' && u.photos.length === 1, 'retry: second attempt of the same batch lands the photo');
}

// ── 5: the leave-page guard ──
{
  const s = makeSandbox();
  const u = s.uploader();
  ok(s.leave() === false, 'leave guard: idle page leaves silently');
  s.state.mode = () => 'network';
  await u.uploadFiles([file('a.jpg')], {});
  ok(s.window.corexUnsavedPhotoCount() === 1 && s.leave() === true, 'leave guard: a FAILED upload (photo only in browser memory) warns before leaving');
  s.state.mode = () => 'ok';
  await u.retryBatch(u.uploadBatches[0]);
  ok(s.window.corexUnsavedPhotoCount() === 0 && s.leave() === false, 'leave guard: once the retry lands, leaving is silent again');
}
{
  const s = makeSandbox();
  const u = s.uploader();
  let midFlight = null;
  s.state.mode = () => { midFlight = s.leave(); return 'ok'; };
  await u.uploadFiles([file('a.jpg')], {});
  ok(midFlight === true, 'leave guard: leaving WHILE a photo is uploading warns');
  ok(s.leave() === false, 'leave guard: after it lands, no warning');
}
{
  // two uploaders on the page (in + out sections) share ONE guard
  const s = makeSandbox();
  const a = s.uploader(), b = s.uploader();
  s.state.mode = () => 'network';
  await b.uploadFiles([file('x.jpg'), file('y.jpg')], {});
  ok(s.window.corexUnsavedPhotoCount() === 2 && s.leave() === true, 'leave guard: a failure in the second uploader is seen too');
  ok(a.uploadBatches.length === 0, 'leave guard: the other uploader is untouched');
}

console.log(fails ? `\n${fails} FAILED` : '\nall passed');
process.exit(fails ? 1 : 0);
