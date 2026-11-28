/* Local, offline browser regression. Input: private rendered WordPress fixture directory. */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const directory = process.argv[2];
  const scope = process.env.DF_BROWSER_SCOPE || 'comprehensive';
  const fixtureFiles = require('./portal-fixture-contract.cjs').fixtures(directory, scope);
  const browser = await chromium.launch({
    executablePath: process.env.DF_BROWSER_EXECUTABLE || '/usr/bin/chromium',
    headless: true,
    args: process.env.DF_BROWSER_NO_SANDBOX === '1' ? ['--no-sandbox'] : [],
  });
  const results = [], failures = [];
  let blockedRequests = 0;
  try {
    for (const width of [390, 768, 1280]) {
      for (const fixture of fixtureFiles) {
        const view = fixture.replace(/^portal-/, '').replace(/\.html$/, '');
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        await page.route('**/*', route => { blockedRequests++; return route.abort(); });
        const css = fs.readFileSync(path.join(__dirname, '../../assets/portal.css'), 'utf8');
        const html = fs.readFileSync(path.join(directory, fixture), 'utf8');
        await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}${css}</style></head><body>${html}</body></html>`);
        await page.evaluate(() => document.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => event.preventDefault())));
        const metrics = await page.evaluate(() => ({
          documentWidth: document.documentElement.scrollWidth,
          currentNavCount: document.querySelectorAll('.df-nav [aria-current="page"]').length,
          navigationLabel: document.querySelector('.df-nav')?.getAttribute('aria-label'),
          unlabeledControls: [...document.querySelectorAll('input:not([type=hidden]),select,textarea,button')].filter(node => !node.disabled && node.getBoundingClientRect().width).filter(node => !(node.getAttribute('aria-label') || node.getAttribute('aria-labelledby') || [...(node.labels || [])].map(label => label.textContent.trim()).join('') || (node.tagName === 'BUTTON' && node.textContent.trim()))).map(node => node.name || node.tagName),
          navMinHeight: Math.min(...[...document.querySelectorAll('.df-nav a')].map(node => node.getBoundingClientRect().height)),
          controls: [...document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea,button')]
            .filter(node => node.getBoundingClientRect().width)
            .map(node => ({ name: node.name || node.textContent.trim().slice(0, 35), height: node.getBoundingClientRect().height })),
        }));
        if (metrics.currentNavCount !== 1 || metrics.navigationLabel !== 'DigiForge navigation') failures.push(`${view}/${width}: current navigation semantics missing`);
        for (const name of metrics.unlabeledControls) failures.push(`${view}/${width}: unlabeled control ${name}`);
        if (metrics.documentWidth > width + 1) failures.push(`${view}/${width}: document overflow ${metrics.documentWidth}`);
        if (metrics.navMinHeight < 44) failures.push(`${view}/${width}: navigation target below 44px`);
        for (const control of metrics.controls) if (control.height < 44) failures.push(`${view}/${width}: ${control.name} target below 44px`);
        if (view.split('--')[0] === 'ai_budget') {
          const hasTemplate = await page.locator('input[name=template_cycle_limit]').count() > 0;
          const input = page.locator(hasTemplate ? 'input[name=template_cycle_limit]' : 'input[name=budget_run]');
          if (await input.count()) {
          await input.fill('10');
          if (await input.inputValue() !== '10') failures.push(`${view}/${width}: template limit interaction failed`);
          await page.keyboard.press('Tab');
          if (!await page.locator(hasTemplate ? 'input[name=template_cycle_seconds]' : 'input[name=budget_day]').evaluate(node => document.activeElement === node)) failures.push(`${view}/${width}: keyboard field navigation failed`);
          }
        }
        results.push({ view, viewport: width, ...metrics });
        await page.close();
      }
    }
  } finally { await browser.close(); }
  console.log(JSON.stringify({ fixture_scope: scope, fixture_count: fixtureFiles.length, scope: 'Offline rendered WordPress fixtures; layout, accessible control names/navigation and field/keyboard interaction only; no server submission or external operations.', results, failures, blocked_network_requests: blockedRequests }, null, 2));
  process.exitCode = failures.length ? 1 : 0;
})().catch(error => { console.error(error.message); process.exitCode = 1; });
