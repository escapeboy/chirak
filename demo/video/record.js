// Records the Chirak demo video (silent) and writes scene start offsets for the narration track.
// Usage: DEMO_TOKEN=... node record.js <dir with narration.tsv and durations.txt from tts.py> [chromium path]
// The wizard is filmed on a local app (:8000; the public demo has no wizard); everything else on the live demo.
const { chromium } = require('playwright');
const fs = require('fs');

const dir = process.argv[2];
const token = process.env.DEMO_TOKEN;
const LOCAL = 'http://127.0.0.1:8000';
const LIVE = 'https://chirak.fleetq.net';
const RUN = 'merchant-triage-orv1o6';
const DECK = 'file://' + require('path').resolve(__dirname, '../../deck/deck.html');
const SIZE = { width: 1600, height: 900 };
const TICKET = 'Поръчах преди 10 дни, все още няма доставка и никой не отговаря на имейлите ми. Искам си парите обратно.';

const durations = Object.fromEntries(fs.readFileSync(`${dir}/durations.txt`, 'utf8').trim().split('\n').map(l => l.split(' ')).map(([k, v]) => [k, parseFloat(v) * 1000]));
const text = Object.fromEntries(fs.readFileSync(`${dir}/narration.tsv`, 'utf8').trim().split('\n').map(l => l.split('\t')));

const wait = ms => new Promise(r => setTimeout(r, ms));

async function caption(page, key) {
  await page.evaluate(t => {
    let el = document.getElementById('__cap');
    if (!el) {
      el = document.createElement('div');
      el.id = '__cap';
      el.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);max-width:1300px;z-index:99999;' +
        'background:rgba(15,23,42,.88);color:#fff;font:500 22px/1.4 -apple-system,system-ui,sans-serif;padding:12px 22px;border-radius:10px;text-align:center';
      document.body.appendChild(el);
    }
    el.textContent = t;
  }, text[key]);
}

async function smoothTo(page, locator, offset = 90) {
  await locator.first().evaluate((el, off) => window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - off, behavior: 'smooth' }), offset);
  await wait(1200);
}

(async () => {
  const browser = await chromium.launch(process.argv[3] ? { executablePath: process.argv[3] } : {});

  // Unlock the live demo with the demo token outside the recording.
  const pre = await browser.newContext({ viewport: SIZE });
  const p0 = await pre.newPage();
  await p0.goto(LIVE + '/');
  await p0.fill('input[name=demo_token]', token);
  await p0.click('form[action$="/unlock"] button');
  await p0.waitForLoadState('networkidle');
  const state = await pre.storageState();
  await pre.close();

  const ctx = await browser.newContext({ viewport: SIZE, storageState: state, recordVideo: { dir: `${dir}/raw`, size: SIZE } });
  const page = await ctx.newPage();
  const t0 = Date.now();
  const scenes = [];
  async function scene(key, body) {
    const start = Date.now();
    scenes.push({ key, at: (start - t0) / 1000 });
    await body();
    await caption(page, key);
    const left = durations[key] + 900 - (Date.now() - start);
    if (left > 0) await wait(left);
  }

  await page.goto(DECK);
  await scene('title', async () => { await caption(page, 'title'); });

  await page.goto(LOCAL + '/runs/new');
  await page.waitForLoadState('networkidle');
  await scene('wizard', async () => {
    await caption(page, 'wizard');
    await wait(4500);
    await smoothTo(page, page.locator('#fields'));
    await wait(4000);
    await smoothTo(page, page.locator('#seeds'));
  });

  await page.goto(`${LIVE}/runs/${RUN}`);
  await page.waitForLoadState('networkidle');
  await scene('run', async () => { await caption(page, 'run'); });
  await scene('eval', async () => {
    await caption(page, 'eval');
    await wait(2500);
    await smoothTo(page, page.getByText('Leakage guard and frozen eval set'));
  });
  await scene('results', async () => {
    await smoothTo(page, page.getByText('Baseline vs iterations'), 20);
    await caption(page, 'results');
  });
  await scene('leakage', async () => {
    await smoothTo(page, page.getByText('Leakage guard and frozen eval set'));
    await caption(page, 'leakage');
  });
  await scene('decisions', async () => {
    await smoothTo(page, page.getByText('Decisions log'));
    await caption(page, 'decisions');
    await wait(4000);
    await smoothTo(page, page.getByText('After iteration 2'), 300);
  });
  await scene('own', async () => {
    await smoothTo(page, page.getByRole('heading', { name: 'Own tickets' }), 20);
    await caption(page, 'own');
  });

  await page.goto(`${LIVE}/runs/${RUN}/playground`);
  await page.waitForLoadState('networkidle');
  const result = {};
  await scene('fresh', async () => {
    await caption(page, 'fresh');
    await wait(1500);
    await page.locator('#input').pressSequentially(TICKET, { delay: 25 });
    await page.getByRole('button', { name: 'Compare' }).click();
    // both answer cards show a latency once the CPU models have answered
    await page.getByText('Latency').nth(1).waitFor({ timeout: 180000 });
  });
  await scene('answer', async () => {
    await caption(page, 'answer');
    result.fresh = await page.locator('main').innerText();
  });

  await page.goto(`${LIVE}/r/${RUN}`);
  await page.waitForLoadState('networkidle');
  await scene('report', async () => {
    await caption(page, 'report');
    await wait(3000);
    await smoothTo(page, page.getByText('Baseline vs iterations'), 20);
  });

  await page.goto(DECK);
  await page.locator('section.slide').nth(7).scrollIntoViewIfNeeded();
  await scene('arch', async () => { await caption(page, 'arch'); });

  const total = (Date.now() - t0) / 1000;
  const video = page.video();
  await ctx.close();
  await browser.close();
  fs.writeFileSync(`${dir}/scenes.json`, JSON.stringify({ scenes, total, video: await video.path(), result }, null, 1));
  console.log(JSON.stringify({ scenes, total }));
})().catch(e => { console.error(e); process.exit(1); });
