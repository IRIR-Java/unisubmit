// Screenshots via Edge's DevTools protocol (no npm deps): real waiting for PDF rendering and true phone emulation.
// usage: UNISUBMIT_URL=https://your-host node tools/cdp-shots.mjs docs/screenshots   (tokens below are from our demo data)
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const out = process.argv[2];
mkdirSync(out, { recursive: true });
const EDGE = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const BASE = process.env.UNISUBMIT_URL || 'http://localhost';
const PORT = 9333;

const shots = [
  { n: '01-student-hand-in', u: '/', w: 1440, h: 900 },
  { n: '02-student-result', u: '/?s=5737f8e355cb53975788', w: 1440, h: 900, full: true },
  { n: '03-lecturer-dashboard', u: '/lecturer.php', w: 1440, h: 900, full: true },
  { n: '04-document-viewer', u: '/view.php?s=631fce7d8a7230607dee&from=lecturer', w: 1440, h: 900, wait: 7000 },
  { n: '05-assignment-brief', u: '/view.php?m=b57003c29ea1718e98a3', w: 1440, h: 900, wait: 7000 },
  { n: '06-adjust-dialog', u: '/lecturer.php', w: 1440, h: 900, js: "document.querySelectorAll('.results .review-btn')[1].click()" },
  { n: '07-processing', u: '/', w: 1440, h: 900, js: "document.getElementById('processing').hidden=false; var s=document.querySelectorAll('.steps li'); s[0].className='done'; s[1].className='on';" },
  { n: '08-mobile-hand-in', u: '/', w: 390, h: 844, mobile: true },
  { n: '09-mobile-student-result', u: '/?s=5737f8e355cb53975788', w: 390, h: 844, mobile: true, full: true },
  { n: '10-mobile-lecturer', u: '/lecturer.php', w: 390, h: 844, mobile: true, full: true },
];

const edge = spawn(EDGE, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${join(tmpdir(), 'unisubmit-cdp')}`,
  '--hide-scrollbars', '--no-first-run', 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));

let wsUrl;
for (let i = 0; i < 40 && !wsUrl; i++) {
  await sleep(250);
  try { wsUrl = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()).find(t => t.type === 'page')?.webSocketDebuggerUrl; } catch {}
}
if (!wsUrl) { console.error('no DevTools endpoint'); edge.kill(); process.exit(1); }

const ws = new WebSocket(wsUrl);
await new Promise(r => ws.addEventListener('open', r));
let id = 0; const pending = new Map(); const waiters = [];
ws.addEventListener('message', ev => {
  const m = JSON.parse(ev.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  if (m.method) waiters.filter(w => w.method === m.method).forEach(w => w.resolve(m));
});
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const once = method => new Promise(resolve => { const w = { method, resolve: m => { waiters.splice(waiters.indexOf(w), 1); resolve(m); } }; waiters.push(w); });

await send('Page.enable');
for (const s of shots) {
  await send('Emulation.setDeviceMetricsOverride', { width: s.w, height: s.h, deviceScaleFactor: 2, mobile: !!s.mobile });
  const loaded = once('Page.loadEventFired');
  await send('Page.navigate', { url: BASE + s.u });
  await loaded;
  await sleep(s.wait || 1800);
  if (s.js) { await send('Runtime.evaluate', { expression: s.js }); await sleep(900); }
  let clip;
  if (s.full) {
    const { result } = await send('Page.getLayoutMetrics');
    clip = { x: 0, y: 0, width: s.w, height: Math.ceil(result.cssContentSize.height), scale: 1 };
  }
  const { result } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: !!s.full, ...(clip ? { clip } : {}) });
  writeFileSync(join(out, s.n + '.png'), Buffer.from(result.data, 'base64'));
  console.log(s.n.padEnd(28), Math.round(result.data.length * 0.75 / 1024) + ' KB');
}
ws.close(); edge.kill();
