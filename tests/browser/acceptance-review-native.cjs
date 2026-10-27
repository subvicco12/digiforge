/* Native WordPress-rendered fixtures, intercepted local HTTP; never production acceptance. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{
 const dir=process.argv[2],root=path.join(__dirname,'../..');
 const css=fs.readFileSync(path.join(root,'assets/portal.css'),'utf8');
 const script=fs.readFileSync(path.join(root,'assets/acceptance-review.js'),'utf8');
 const browser=await chromium.launch({headless:true,...(process.env.DF_BROWSER_EXECUTABLE?{executablePath:process.env.DF_BROWSER_EXECUTABLE}:{})});let checks=0;
 try {for(const shop of ['digital','personalized_pod'])for(const width of [390,768,1280])for(const pretty of [false,true]){
  const page=await browser.newPage({viewport:{width,height:1000}});let calls=0,fail=false;
  let html=fs.readFileSync(path.join(dir,shop+'.html'),'utf8');
  html=html.replace(/data-endpoint="[^"]+"/,`data-endpoint="${pretty?'https://example.org/wp-json/digiforge/v1/reviewed-contracts/'+shop+'/':'https://example.org/?rest_route=/digiforge/v1/reviewed-contracts/'+shop+'/'}"`);
  await page.route('**/*',async route=>{
   const r=route.request(),url=new URL(r.url());
   if(url.pathname==='/fixture')return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}'+css+'</style><div class="df-portal-shell"><aside class="df-portal-sidebar">Synthetic operator navigation</aside><main class="df-portal-main">'+html+'</main></div><script>'+script+'</script></html>'});
   const target=url.searchParams.get('rest_route')||url.pathname.replace('/wp-json','');
   assert.ok(target.startsWith('/digiforge/v1/reviewed-contracts/'+shop+'/acceptance'));assert.ok(r.headers()['x-wp-nonce']);calls++;
   if(fail)return route.fulfill({status:503,body:'private diagnostic must not render'});
   const body=r.method()==='POST'?JSON.parse(r.postData()):null;
   if(body)assert.equal(body.confirm,'CONFIRM_LOCAL_ACCEPTANCE_REVIEW');
   const identity=body?.input||body||Object.fromEntries(url.searchParams);
   return route.fulfill({contentType:'application/json',body:JSON.stringify({shop_key:shop,criterion:identity.criterion,scope:identity.scope,status:'PENDING',revision:1,proposal_hash:'a'.repeat(64),payload:{review:'<img src=x onerror="window.bad=true">'},external_execution_authorized:false})});
  });
  await page.goto('https://example.org/fixture');const panel=page.locator('[data-acceptance-review]');await panel.locator('summary').click();
  const inspect=panel.locator('[data-acceptance-write=inspect]'),proposal=panel.locator('[data-acceptance-write=proposal]'),decision=panel.locator('[data-acceptance-write=decision]'),output=panel.locator('[role=status]');
  await inspect.locator('[name=scope]').fill('synthetic-product');await inspect.locator('button').click();await output.filter({hasText:'PENDING'}).waitFor();assert.equal(calls,1);checks++;
  await proposal.locator('button').click();assert.equal(calls,1);checks++;
  await proposal.locator('[name=confirm]').check();await proposal.locator('[name=confirm]').focus();await page.keyboard.press('Tab');assert.ok(await proposal.locator('button').evaluate(n=>n===document.activeElement));checks++;
  await page.keyboard.press('Enter');await output.filter({hasText:'PENDING'}).waitFor();assert.equal(calls,2);assert.equal(await output.locator('img').count(),0);assert.equal(await page.evaluate(()=>window.bad),undefined);checks+=3;
  await decision.locator('[name=payload]').fill(JSON.stringify({criterion:'BP-portal-7-supplier-readiness',scope:'synthetic-product',expected_revision:1,proposal_hash:'a'.repeat(64),decision:'REJECTED'}));await decision.locator('[name=confirm]').check();await decision.locator('button').click();await output.filter({hasText:'PENDING'}).waitFor();assert.equal(calls,3);checks++;
  fail=true;await inspect.locator('button').click();await output.filter({hasText:'UNAVAILABLE'}).waitFor();assert.doesNotMatch(await output.textContent(),/private diagnostic|PENDING/);checks++;
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth)<=width+1,'Viewport overflow '+shop+' '+width);checks++;
  assert.equal(await panel.locator('input:not([type=hidden]),textarea,select').evaluateAll(nodes=>nodes.filter(n=>!n.labels?.length&&!n.getAttribute('aria-label')).length),0);checks++;
  if(process.env.DF_ACCEPTANCE_SCREENSHOTS)await page.screenshot({path:path.join(process.env.DF_ACCEPTANCE_SCREENSHOTS,`${shop}-${width}-${pretty?'pretty':'plain'}.png`),fullPage:true});
  await page.close();
 }console.log(JSON.stringify({status:'PASS',checks,shops:['digital','personalized_pod'],widths:[390,768,1280],permalinks:['plain','pretty'],synthetic_native_fixtures:true,production_certified:false}));}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
