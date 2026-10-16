const {chromium}=require('playwright');
const {createOfflineContext}=require('../../includes/Research/offline/tinyfish-interception.cjs');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.DF_BROWSER_EXECUTABLE||'/usr/bin/chromium',headless:true,args:process.env.DF_BROWSER_NO_SANDBOX==='1'?['--no-sandbox']:[]});let checks=0;
 try {
  const url='https://printify.com/app/products/68/generic-brand/mug';
  const run=await createOfflineContext(browser,{[url]:'<h1>SYNTHETIC OFFLINE CATALOG</h1>'});const page=await run.context.newPage();
  await page.goto(url);if(await page.locator('h1').textContent()!=='SYNTHETIC OFFLINE CATALOG')throw Error('Fixture failed');checks++;
  for(const [method,target]of [['POST',url],['PUT',url],['PATCH',url],['DELETE',url],['GET','https://evil.invalid/steal'],['GET','https://printify.com/app/store/28930593/orders/1']]){
   const result=await page.evaluate(async([m,u])=>{try{await fetch(u,{method:m});return false;}catch{return true;}},[method,target]);if(!result)throw Error('Unauthorized network allowed');checks++;
  }
  await page.evaluate(()=>new Promise(resolve=>{const w=new WebSocket('wss://evil.invalid/socket');w.onerror=()=>resolve();w.onclose=()=>resolve();setTimeout(resolve,1000);}));
  await page.evaluate(()=>{const f=document.createElement('iframe');f.src='https://printify.com/app/products/68/generic-brand/mug';document.body.append(f);});await page.waitForTimeout(100);
  if(!run.events.some(e=>e.kind==='websocket'&&!e.allowed))throw Error('WebSocket not intercepted');checks++;
  if(run.events.filter(e=>e.allowed).length!==1)throw Error('Subrequest or frame admitted');checks++;
  try{const extra=await run.context.newPage();await extra.goto(url).catch(()=>{});}catch{}await page.waitForTimeout(100);if(run.context.pages().length!==1||!run.events.some(e=>e.kind==='popup'&&!e.allowed))throw Error('Popup not isolated');checks++;
  await page.evaluate(()=>Promise.race([navigator.serviceWorker.register('/worker.js').catch(()=>null),new Promise(r=>setTimeout(r,100))]));if(run.context.serviceWorkers().length!==0)throw Error('Service worker created');checks++;
  const blockedBefore=run.events.filter(e=>e.kind==='request'&&!e.allowed).length;await page.evaluate(()=>location.assign('https://evil.invalid/redirect-target'));await page.waitForTimeout(100);if(run.events.filter(e=>e.kind==='request'&&!e.allowed).length<=blockedBefore)throw Error('Client redirect escaped interception');checks++;
  let denied=false;try{await page.goto('https://printify.com/app/products/68/generic-brand/mug?redirect=https://evil.invalid');}catch{denied=true;}if(!denied)throw Error('Unauthorized redirect target accepted');checks++;
  await run.close();if(run.liveExecutionAuthorized!==false)throw Error('Live authority invented');checks++;
  let closed=false;try{await createOfflineContext({newContext:async()=>({close:async()=>{closed=true;}})},{[url]:'synthetic'});throw Error('Missing interception accepted');}catch{if(!closed)throw Error('Failed context not closed');}checks++;
  console.log(JSON.stringify({checks,intercepted_requests:run.events.filter(e=>e.kind==='request').length,blocked_requests:run.events.filter(e=>e.kind==='request'&&!e.allowed).length,fixture_documents:run.events.filter(e=>e.kind==='request'&&e.allowed).length,evidence:'OFFLINE_CHROMIUM_SYNTHETIC_ONLY'}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
