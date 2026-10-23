// Crawls shop.gplxlife.com in a real browser and saves what it finds into shop-snapshot/:
// every JSON API response, the rendered text of each page, and a list of product links.
const { chromium } = require('playwright');
const fs = require('fs');
const OUT = 'shop-snapshot', ORIGIN = 'https://shop.gplxlife.com';
fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(OUT + '/api', { recursive: true });
fs.mkdirSync(OUT + '/pages', { recursive: true });
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  let n = 0;
  const apiLog = [];
  ctx.on('response', async (res) => {
    try {
      const ct = res.headers()['content-type'] || '';
      if (!ct.includes('json')) return;
      const body = await res.text();
      const f = `api/${String(++n).padStart(4, '0')}.json`;
      fs.writeFileSync(`${OUT}/${f}`, body);
      apiLog.push({ file: f, status: res.status(), url: res.url(), bytes: body.length });
    } catch (e) {}
  });
  const seen = new Set(), queue = [ORIGIN + '/'], products = new Set(), pages = [];
  const page = await ctx.newPage();
  while (queue.length && seen.size < 250) {
    const url = queue.shift();
    if (seen.has(url)) continue;
    seen.add(url);
    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
      // scroll to trigger lazy lists
      for (let i = 0; i < 15; i++) { await page.mouse.wheel(0, 2500); await page.waitForTimeout(300); }
      await page.waitForTimeout(1000);
      const text = await page.evaluate(() => document.body.innerText);
      const links = await page.$$eval('a[href]', (as) => as.map((a) => a.href));
      const slug = url.replace(ORIGIN, '').replace(/[^a-z0-9]+/gi, '_') || 'home';
      fs.writeFileSync(`${OUT}/pages/${slug.slice(0, 120)}.txt`, url + '\n\n' + text);
      pages.push({ url, title: await page.title(), links: links.length });
      for (const l of links) {
        if (!l.startsWith(ORIGIN)) continue;
        const u = l.split('#')[0];
        if (/\/product-details\/product\//.test(u)) products.add(u.split('?')[0]);
        if (/(cart|checkout|login|signin|sign-in|account|logout)/i.test(u)) continue;
        if (!seen.has(u)) queue.push(u);
      }
    } catch (e) {
      pages.push({ url, error: String(e).slice(0, 200) });
    }
  }
  fs.writeFileSync(`${OUT}/pages.json`, JSON.stringify(pages, null, 1));
  fs.writeFileSync(`${OUT}/api-log.json`, JSON.stringify(apiLog, null, 1));
  fs.writeFileSync(`${OUT}/product-links.txt`, [...products].sort().join('\n') + '\n');
  console.log('pages', pages.length, 'product links', products.size, 'api responses', apiLog.length);
  await browser.close();
})();
