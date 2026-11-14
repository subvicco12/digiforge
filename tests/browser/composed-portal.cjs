/* Native authenticated WordPress fixtures + browser keyboard journeys.
 * Server decisions are independently exercised by ComposedPersonalizedOrderAcceptanceTest;
 * this file does not claim a browser-authenticated HTTP server or deployed-site acceptance. */
const {chromium}=require('playwright');
const fs=require('fs'),path=require('path');
(async()=>{
  const directory=process.argv[2], meta=JSON.parse(fs.readFileSync(path.join(directory,'journey.json'),'utf8'));
  if(meta.evidence_kind!=='SYNTHETIC_NATIVE_FIXTURE'||!Number.isInteger(meta.package_id))throw new Error('Native synthetic journey manifest required');
  const css=fs.readFileSync(path.join(__dirname,'../../assets/portal.css'),'utf8');
  const browser=await chromium.launch({executablePath:process.env.DF_BROWSER_EXECUTABLE||'/usr/bin/chromium',headless:true,args:process.env.DF_BROWSER_NO_SANDBOX==='1'?['--no-sandbox']:[]});
  let requests=0,checks=0;
  try{
    for(const width of [390,768,1280]){
      const page=await browser.newPage({viewport:{width,height:900}});
      await page.route('**/*',route=>{requests++;return route.abort();});
      async function load(name){const html=fs.readFileSync(path.join(directory,name+'.html'),'utf8');await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}${css}</style>${html}`);}
      const shops=['digital','personalized_pod','standard_pod','jewelry'];
      for(const shop of shops){
        await load('supplier-'+shop);
        if(await page.locator('select[name=df_shop]').inputValue()!==shop)throw new Error('Supplier selector scope mismatch');checks++;
        const main=await page.locator('.df-portal-main').textContent();
        if(!main.includes('composed-'+shop+'-'))throw new Error('Owned supplier evidence missing');checks++;
        for(const foreign of shops){if(foreign!==shop&&main.includes('composed-'+foreign+'-'))throw new Error('Foreign supplier identity leaked');}checks++;
        await load('fulfillment-'+shop);
        const providers=await page.locator('.df-portal-main').textContent();
        if(!providers.includes('composed-'+shop))throw new Error('Scoped provider plan missing');checks++;
        for(const foreign of shops){if(foreign!==shop&&providers.includes('composed-'+foreign))throw new Error('Foreign provider plan leaked');}checks++;
      }
      await load('package-pending');
      const form=page.locator('form').filter({has:page.locator(`input[name="package_id"][value="${meta.package_id}"]`)});
      if(await form.count()!==1)throw new Error('Exact native pending package form missing');checks++;
      if(await page.locator('select[name=df_shop]').inputValue()!==meta.shop)throw new Error('Wrong selected shop');checks++;
      const checkbox=form.locator('input[name=df_confirm_review]');await checkbox.check();await checkbox.focus();await page.keyboard.press('Tab');
      const button=form.getByRole('button',{name:'Record human package review',exact:true});
      if(!await button.evaluate(n=>n===document.activeElement))throw new Error('Keyboard confirmation/submit order failed');checks++;
      await form.evaluate(node=>{node.addEventListener('submit',event=>{event.preventDefault();window.__composedSubmission=Object.fromEntries(new FormData(node));});});
      await page.keyboard.press('Enter');
      const body=await page.evaluate(()=>window.__composedSubmission);
      if(!body||body.package_id!==String(meta.package_id)||body.df_confirm_review!=='1'||body.df_confirmation_shop!==meta.shop||!body._wpnonce)throw new Error('Native confirmation payload missing or wrong scoped target');checks++;
      // Project the separately captured native server outcome, without simulating an approval.
      await load('package-approved');
      if(await page.locator(`input[name=package_id][value="${meta.package_id}"]`).count()!==0)throw new Error('Stale review action survived native acceptance');checks++;
      if(!await page.getByText('APPROVED_PACKAGE',{exact:true}).count())throw new Error('Native accepted state missing');checks++;
      await load('package-drift');
      if(!await page.getByText('REVALIDATION_REQUIRED',{exact:true}).count())throw new Error('Native drift did not require revalidation');checks++;
      await load('package-denied');
      if(await page.locator('input[name=package_id]').count()!==0)throw new Error('Foreign actor received a review action');checks++;
      for(const name of ['package-pending','package-approved','package-drift']){
        await load(name);
        if(await page.evaluate(()=>document.documentElement.scrollWidth)>width+1)throw new Error('Viewport overflow at '+width);checks++;
        const unlabeled=await page.evaluate(()=>[...document.querySelectorAll('input:not([type=hidden]),select,textarea,button')].filter(n=>n.getBoundingClientRect().width&&!n.disabled).filter(n=>!(n.getAttribute('aria-label')||n.getAttribute('aria-labelledby')||(n.labels&&n.labels.length)||(n.tagName==='BUTTON'&&n.textContent.trim()))).length);
        if(unlabeled)throw new Error('Unlabelled native control');checks++;
      }
      await page.close();
    }
    if(requests)throw new Error('Unexpected network requests: '+requests);
    console.log(JSON.stringify({status:'PASS',checks,widths:[390,768,1280],unexpected_network_requests:requests,evidence:'native_authenticated_handlers_plus_fixture_driven_browser_not_live_http_e2e'}));
  }finally{await browser.close();}
})().catch(error=>{console.error(error.message);process.exit(1);});
