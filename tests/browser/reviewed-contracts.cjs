/* Synthetic REST responses test client interaction only; native WordPress tests enforce authorization. */
const {chromium} = require('playwright');
const fs = require('fs');
const path = require('path');
(async () => {
  const html = fs.readFileSync(path.join(process.argv[2], 'reviewed-contracts.html'), 'utf8').replaceAll('http://example.org', 'https://example.org');
  const css = fs.readFileSync(path.join(__dirname, '../../assets/portal.css'), 'utf8');
  const browser = await chromium.launch({executablePath: process.env.DF_BROWSER_EXECUTABLE || '/usr/bin/chromium',headless:true,args:process.env.DF_BROWSER_NO_SANDBOX === '1'?['--no-sandbox']:[]});
  let checks=0, unexpected=0;
  try {
    for(const width of [390,768,1280]) {
      const page=await browser.newPage({viewport:{width,height:900}});
      let calls=0;
      await page.route('**/*', async route => {
        const request=route.request(), url=new URL(request.url());
        if(url.pathname==='/local-reviewed-contract-test') return route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}${css}</style>${html}`});
        const apiPath=url.searchParams.get('rest_route') || url.pathname.replace(/^\/wp-json/,'');
        if(apiPath==='/digiforge/v1/reviewed-contracts/digital/policy' && request.method()==='POST') {
          calls++; const data=request.postDataJSON();
          if(data.input.enabled!==false || data.input.capability!=='research' || data.expected_revision!==0 || !Number.isInteger(data.expires_at)) throw new Error('Typed proposal mismatch');
          return route.fulfill({status:201,contentType:'application/json',body:JSON.stringify({policy_key:'production:SHOP::research',revision:1,proposal_hash:'a'.repeat(64),version:1,status:'PENDING',external_execution_authorized:false})});
        }
        if(apiPath==='/digiforge/v1/reviewed-contracts/digital/policy/decision' && request.method()==='POST') {
          calls++;return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({message:'Independent reviewer required.'})});
        }
        unexpected++;return route.abort();
      });
      await page.goto('https://example.org/local-reviewed-contract-test');
      await page.addScriptTag({path:path.join(__dirname,'../../assets/reviewed-contracts.js')});
      const details=page.locator('[data-reviewed-contracts] details').first();await details.locator('summary').click();
      const form=page.locator('[data-contract-kind="policy"]');
      await form.locator('[name=effective_at]').fill('2026-10-15T10:00');
      await form.locator('[name=expires_at]').fill('2026-10-16T10:00');
      await form.locator('[name=expected_revision]').focus();await page.keyboard.press('Tab');
      if(!await form.locator('button[type=submit]').evaluate(n=>n===document.activeElement)) throw new Error('Keyboard submit order failed');checks++;
      await form.locator('button[type=submit]').click();
      await page.locator('[data-contract-result="policy"]').filter({hasText:'PENDING'}).waitFor();
      if(await page.locator('[data-contract-read="policy"] [name=key]').inputValue()!=='production:SHOP::research')throw new Error('Canonical key was not retained');checks++;
      await page.getByRole('button',{name:'APPROVED local version 1',exact:true}).click();
      await page.getByText('Independent reviewer required. Reload current evidence before retrying.',{exact:true}).waitFor();
      if(await page.locator('[data-contract-decisions="policy"] button').count()!==0||calls!==2)throw new Error('Stale/replayed decision controls remain');checks++;
      if(await page.evaluate(()=>document.documentElement.scrollWidth)>width+1)throw new Error(`Overflow at ${width}`);checks++;
      await page.close();
    }
    if(unexpected!==0)throw new Error('Unexpected network requests: '+unexpected);
    console.log(JSON.stringify({status:'PASS',checks,widths:[390,768,1280],unexpected_network_requests:unexpected,synthetic_client_evidence_only:true}));
  } finally {await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1);});
