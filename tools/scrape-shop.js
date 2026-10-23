// Pulls the full product catalog of shop.gplxlife.com (a LeadConnector/HighLevel store)
// into shop-snapshot/: every product from the public catalog API (all pages), which
// collections each one is in, and the rendered text of every product page.
const { chromium } = require('playwright');
const fs = require('fs');
const OUT = 'shop-snapshot', ORIGIN = 'https://shop.gplxlife.com';
const API = 'https://backend.leadconnectorhq.com/products/public';
const BASEQ = 'locationId=m2m0fILRjYwqHwEndk7z&storeId=QYOabpIQtDqpoOBEuF3t&sendWishlistStatus=false&showAltImage=false&sortField=name&sortOrder=asc&includePriceRange=true';
fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(OUT + '/pages', { recursive: true });
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(ORIGIN + '/products-list', { waitUntil: 'networkidle', timeout: 60000 });
  const shownTotal = await page.evaluate(() => (document.body.innerText.match(/(\d+)\s+products/) || [])[1]);
  // Collection slugs linked from the shop
  const slugs = new Set();
  for (const u of [ORIGIN + '/', ORIGIN + '/products-list']) {
    await page.goto(u, { waitUntil: 'networkidle', timeout: 60000 });
    (await page.$$eval('a[href]', (as) => as.map((a) => a.href))).forEach((h) => {
      const m = h.match(/\/collections\/([a-z0-9-]+)/i); if (m) slugs.add(m[1]);
    });
  }
  const get = (q) => page.evaluate(async (url) => { const r = await fetch(url); return { status: r.status, body: await r.json() }; }, `${API}?${q}`);
  async function all(extra) {
    const out = []; let total = null;
    for (let off = 0; off < 2000; off += 20) {
      const r = await get(`limit=20&offset=${off}&${BASEQ}${extra}`);
      if (r.status !== 200) throw new Error('API ' + r.status);
      total = r.body.total; out.push(...r.body.products);
      if (!r.body.products.length || out.length >= total) break;
    }
    return { total, products: out };
  }
  const catalog = await all('');
  const collections = {};
  for (const s of slugs) {
    const c = await all(`&collectionSlug=${s}`);
    collections[s] = { total: c.total, ids: c.products.map((p) => p._id) };
  }
  for (const p of catalog.products) {
    delete p.description;
    try {
      await page.goto(`${ORIGIN}/product-details/product/${p._id}`, { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(800);
      const text = await page.evaluate(() => document.body.innerText);
      fs.writeFileSync(`${OUT}/pages/${p._id}.txt`, text);
    } catch (e) { p.pageError = String(e).slice(0, 200); }
  }
  fs.writeFileSync(`${OUT}/catalog.json`, JSON.stringify({ shownTotal, apiTotal: catalog.total, count: catalog.products.length, collections, products: catalog.products }, null, 1));
  console.log('shown', shownTotal, 'api total', catalog.total, 'fetched', catalog.products.length, 'collections', Object.keys(collections));
  await browser.close();
})();
