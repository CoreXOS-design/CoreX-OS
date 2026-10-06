// Reads the text of the Subscription Agreement recipient page AS A REAL BROWSER renders it.
// Usage: node scripts/verify-agreement-web-text.cjs <recipient-url> <out.txt>
// Puppeteer is resolved from $PUPPETEER_PATH, the project's node_modules, or the QA1 checkout's.
// Only the agreement sheets are read (letterhead, footer and initial buttons are page furniture); form controls
// carry no text, so a blank filled by a field reads as nothing — exactly what the comparison expects.
const fs = require('fs');
let puppeteer;
for (const p of [process.env.PUPPETEER_PATH, 'puppeteer', '/corex-qa1/node_modules/puppeteer']) {
  if (!p) continue;
  try { puppeteer = require(p); break; } catch (e) { /* try the next */ }
}
if (!puppeteer) { console.error('puppeteer not found'); process.exit(2); }
const [url, out] = process.argv.slice(2);

(async () => {
  const browser = await puppeteer.launch({ executablePath: process.env.CHROME || '/usr/bin/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1300, height: 1000 });
  await page.goto(url, { waitUntil: 'networkidle2' });
  await new Promise((r) => setTimeout(r, 1500)); // let the page script settle (it recalculates the fee table on load)
  const text = await page.evaluate(() => {
    const SKIP = new Set(['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON', 'SCRIPT', 'STYLE', 'CANVAS', 'NOSCRIPT', 'OPTION']);
    const INLINE = new Set(['SPAN', 'STRONG', 'EM', 'B', 'I', 'SUP', 'SUB', 'A', 'U', 'LABEL', 'SMALL']);
    let out = '';
    const walk = (n) => {
      if (n.nodeType === 3) { out += n.nodeValue; return; }
      if (n.nodeType !== 1 || SKIP.has(n.tagName)) return;
      if (getComputedStyle(n).display === 'none' || n.hidden) return;
      const inline = INLINE.has(n.tagName);
      if (!inline) out += ' ';
      n.childNodes.forEach(walk);
      if (!inline) out += ' ';
    };
    document.querySelectorAll('.sheet-body').forEach((b) => { walk(b); out += '\n'; });
    return out;
  });
  const sheets = await page.evaluate(() => document.querySelectorAll('.sheet-body').length);
  fs.writeFileSync(out, text);
  console.log('sheets read: ' + sheets + ', characters: ' + text.length);
  await browser.close();
})();
