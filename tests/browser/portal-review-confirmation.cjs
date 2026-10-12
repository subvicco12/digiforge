/* Browser-native confirmation validation. Inputs are actual private WordPress renders; HTTP is aborted. */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const directory = process.argv[2];
  const fixtures = ['confirmation-research.html', 'confirmation-order.html', 'confirmation-draft-qa.html'];
  for (const file of fixtures) if (!directory || !fs.existsSync(path.join(directory, file))) throw new Error(`Missing actual confirmation fixture: ${file}`);
  const browser = await chromium.launch({ executablePath: process.env.DF_BROWSER_EXECUTABLE || '/usr/bin/chromium', headless: true, args: process.env.DF_BROWSER_NO_SANDBOX === '1' ? ['--no-sandbox'] : [] });
  const results = [], failures = [];
  let networkRequests = 0;
  try {
    for (const width of [390, 768, 1280]) for (const file of fixtures) {
      const page = await browser.newPage({ viewport: { width, height: 900 }, javaScriptEnabled: false });
      let formPosts = 0, signedBindingSubmitted = false;
      await page.route('**/*', route => {
        networkRequests++;
        const request = route.request();
        if (request.isNavigationRequest() && request.method() === 'POST') {
          formPosts++;
          const values = new URLSearchParams(request.postData() || '');
          signedBindingSubmitted = values.get('df_confirm_review') === '1' && /^[a-f0-9]{32}$/.test(values.get('df_confirmation_nonce') || '') && [...values.keys()].some(key => key.startsWith('df_confirmation_tokens['));
          return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><p>Local form intercepted. No server request dispatched.</p>' });
        }
        return route.abort();
      });
      const css = fs.readFileSync(path.join(__dirname, '../../assets/portal.css'), 'utf8');
      const raw = fs.readFileSync(path.join(directory, file), 'utf8');
      const html = raw.includes('<body>') ? raw.split('<body>')[1].split('</body>')[0] : raw;
      await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}${css}</style></head><body>${html}</body></html>`);
      const fieldset = page.locator('fieldset.df-confirmation').first();
      if (!await fieldset.count()) { failures.push(`${file}/${width}: actual confirmation fieldset missing`); await page.close(); continue; }
      const checkbox = fieldset.locator('input[type=checkbox][name=df_confirm_review]');
      const form = fieldset.locator('xpath=ancestor::form[1]');
      const metadata = await fieldset.evaluate(node => ({ legend: node.querySelector('legend')?.textContent.trim(), effectId: node.getAttribute('aria-describedby'), labelled: !!node.querySelector('input[type=checkbox]')?.labels.length, labelHeight: node.querySelector('label')?.getBoundingClientRect().height, summaryHasTarget: /#\d+/.test(node.textContent), evidenceHash: /Current evidence SHA-256\s+[a-f0-9]{64}/.test(node.textContent), scopeVisible: /Shop: (digital|personalized_pod|standard_pod|jewelry)/.test(node.textContent) }));
      if (!metadata.legend || !metadata.labelled || !metadata.effectId || !metadata.summaryHasTarget || !metadata.evidenceHash || !metadata.scopeVisible) failures.push(`${file}/${width}: accessible exact target/evidence/scope summary missing`);
      if (metadata.labelHeight < 44) failures.push(`${file}/${width}: confirmation label target below 44px`);
      const submit = form.locator('button[type=submit],button:not([type])').first();
      await checkbox.uncheck();
      await submit.click();
      if (formPosts !== 0) failures.push(`${file}/${width}: unchecked confirmation allowed submit`);
      await checkbox.focus();
      await page.keyboard.press('Space');
      if (!await checkbox.isChecked()) failures.push(`${file}/${width}: keyboard confirmation failed`);
      const documentWidth = await page.evaluate(() => document.documentElement.scrollWidth);
      if (documentWidth > width + 1) failures.push(`${file}/${width}: document overflow ${documentWidth}`);
      await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), submit.click()]);
      if (formPosts !== 1 || !signedBindingSubmitted) failures.push(`${file}/${width}: explicit confirmation did not submit its signed binding once`);
      results.push({ fixture: file, viewport: width, no_application_javascript: true, documentWidth, ...metadata, unchecked_submission_blocked: true, keyboard_confirmation_submitted_once: formPosts === 1, signed_binding_submitted: signedBindingSubmitted });
      await page.close();
    }
  } finally { await browser.close(); }
  console.log(JSON.stringify({ scope: 'Actual local WordPress confirmation renders; native required checkbox, keyboard, accessible target/evidence and mobile layout. No server submission or provider requests.', results, failures, blocked_network_requests: networkRequests }, null, 2));
  process.exitCode = failures.length ? 1 : 0;
})().catch(error => { console.error(error.message); process.exitCode = 1; });
