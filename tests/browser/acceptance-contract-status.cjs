/* Synthetic browser responses test rendering only; native WP tests enforce authority. */
const {chromium}=require('playwright');
const fs=require('fs'), path=require('path'), assert=require('node:assert/strict');
(async()=>{
 const fixture=fs.readFileSync(path.join(process.argv[2],'acceptance-status.html'),'utf8').replaceAll('http://example.org','https://example.org');
 const browser=await chromium.launch({executablePath:process.env.DF_BROWSER_EXECUTABLE||'/usr/bin/chromium',args:process.env.DF_BROWSER_NO_SANDBOX==='1'?['--no-sandbox']:[]});let checks=0;
 try{for(const width of [390,768,1280])for(const pretty of [false,true]){
  const page=await browser.newPage({viewport:{width,height:900}});let calls=0,fail=false,mode='match';
  const html=pretty?fixture.replace(/data-endpoint="[^"]+"/,'data-endpoint="https://example.org/wp-json/digiforge/v1/reviewed-contracts/digital/"'):fixture;
  await page.route('**/*',async route=>{
   const r=route.request(),u=new URL(r.url());
   if(u.pathname==='/fixture')return route.fulfill({contentType:'text/html',body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'+html});
   assert.equal(u.searchParams.get('rest_route')||u.pathname.replace(/^\/wp-json/,''),'/digiforge/v1/reviewed-contracts/digital/acceptance');assert.equal(r.method(),'GET');assert.equal(r.postData(),null);assert.ok(['sandbox','production'].includes(u.searchParams.get('environment')));assert.ok(r.headers()['x-wp-nonce']);calls++;
   if(fail)return route.fulfill({status:503,body:'private backend diagnostic'});
   const scope=u.searchParams.get('scope');if(scope==='slow')await new Promise(resolve=>setTimeout(resolve,150));
   const data={shop_key:'digital',criterion:u.searchParams.get('criterion'),scope,status:'CURRENT_REVIEW',review_status:'APPROVED',expires_at:1800000000,revoked:false,evidence_references:[{reference:'<img src=x onerror="window.bad=true">'}]};
   data.policy_evaluation={shop_key: mode==='foreign-policy'?'personalized_pod':'digital', criterion:data.criterion, scope, environment:u.searchParams.get('environment'), state:'LOCAL_CALCULATED', metrics:{conversion_percent:{state:'LOCAL_CALCULATED',value:50}}, supplier_approved:true, production_authorized:true, linguistic_approved:true};
   if(mode!=='legacy')data.evidence_resolution={shop_key:mode==='foreign'?'personalized_pod':'digital',criterion:data.criterion,scope,environment:u.searchParams.get('environment'),resolution_state:mode==='unknown'?'UNKNOWN':'MATCHED_LOCAL_RECORDS',matched_record_count:mode==='unknown'?0:1,references:[{reference:'<img src=x onerror="window.bad=true">',state:mode==='unknown'?'UNVERIFIED':'MATCHED_LOCAL_POLICY',purpose_verified:true,external_execution_authorized:true}],external_execution_authorized:true};
   return route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
  });
  await page.goto('https://example.org/fixture');await page.addScriptTag({path:path.join(__dirname,'../../assets/acceptance-contract-status.js')});
  const panel=page.locator('[data-acceptance-status]');await panel.locator('summary').click();
  const scope=panel.locator('[name=scope]'),button=panel.locator('button'),output=panel.locator('[role=status]');
  const environment=panel.locator('[name=environment]');await scope.fill('fixture-v1');await button.click();assert.equal(calls,0);checks++;
  await environment.selectOption('sandbox');await scope.focus();await page.keyboard.press('Tab');assert.ok(await environment.evaluate(n=>n===document.activeElement));await page.keyboard.press('Tab');assert.ok(await button.evaluate(n=>n===document.activeElement));await page.keyboard.press('Enter');await output.filter({hasText:'MATCHED_LOCAL_RECORDS'}).waitFor();
  const displayed=JSON.parse(await output.textContent());assert.equal(displayed.evidence_resolution.purpose_verified,false);assert.equal(displayed.evidence_resolution.external_execution_authorized,false);assert.equal(displayed.evidence_resolution.references[0].external_execution_authorized,undefined);checks+=3;
  assert.equal(displayed.policy_evaluation.metrics.conversion_percent.value,50);assert.equal(displayed.policy_evaluation.supplier_approved,false);assert.equal(displayed.policy_evaluation.production_authorized,false);checks+=3;
  mode='foreign-policy';await button.click();await output.filter({hasText:'UNAVAILABLE'}).waitFor();checks++;
  mode='unknown';await environment.selectOption('production');await button.click();await output.filter({hasText:'CURRENT_REVIEW'}).waitFor();assert.equal(JSON.parse(await output.textContent()).evidence_resolution.resolution_state,'UNKNOWN');checks++;
  mode='foreign';await button.click();await output.filter({hasText:'UNAVAILABLE'}).waitFor();assert.doesNotMatch(await output.textContent(),/MATCHED_LOCAL_RECORDS/);checks++;
  mode='legacy';await button.click();await output.filter({hasText:'CURRENT_REVIEW'}).waitFor();assert.equal(JSON.parse(await output.textContent()).evidence_resolution.resolution_state,'UNKNOWN');checks++;
  mode='match';
  assert.equal(await output.locator('img').count(),0);assert.equal(await page.evaluate(()=>window.bad),undefined);assert.match(await output.textContent(),/UNVERIFIED/);assert.match(await output.textContent(),/UNKNOWN/);checks+=4;
  fail=true;await button.click();await output.filter({hasText:'UNAVAILABLE'}).waitFor();assert.doesNotMatch(await output.textContent(),/CURRENT_REVIEW|private backend/);checks++;
  fail=false;await scope.fill('slow');await button.click();await page.waitForTimeout(30);await scope.fill('changed');await page.waitForTimeout(200);assert.equal(await output.textContent(),'');assert.ok(calls>=3);checks++;
  assert.equal(await panel.locator('[name=decision],[name=token],[data-contract-decisions]').count(),0);checks++;
  await page.close();
 }console.log(JSON.stringify({checks,viewports:[390,768,1280],permalinks:['plain','pretty'],synthetic_only:true}));}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
