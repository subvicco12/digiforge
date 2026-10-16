'use strict';
/* Offline interception exerciser. Never forwards requests or connects to TinyFish. */
async function createOfflineContext(browser, fixtures) {
  const urls=Object.keys(fixtures);
  const canonical=/^https:\/\/printify\.com\/(?:app\/products\/[1-9][0-9]{0,9}\/[a-z0-9-]+\/[a-z0-9-]+|app\/print-provider\/[1-9][0-9]{0,9}\/[a-z0-9-]+|shipping-rates\/[a-z0-9-]+\/)$/;
  if(!urls.length||urls.length>10||urls.some(u=>!canonical.test(u)||typeof fixtures[u]!=='string'||Buffer.byteLength(fixtures[u])>65536))throw Error('Bounded public synthetic fixtures required');
  const context=await browser.newContext({serviceWorkers:'block',acceptDownloads:false});
  const events=[];let ready=false,primaryPage=null;
  try {
    if(typeof context.routeWebSocket!=='function')throw Error('WebSocket interception unavailable');
    await context.routeWebSocket('**/*',ws=>{events.push({kind:'websocket',allowed:false});ws.close();});
    await context.route('**/*',async route=>{
      const req=route.request();let top=false;try{top=req.frame().parentFrame()===null&&req.frame().page()===primaryPage;}catch{}
      const allowed=ready&&req.method()==='GET'&&req.resourceType()==='document'&&req.postData()===null&&top&&!req.redirectedFrom()&&Object.hasOwn(fixtures,req.url());
      events.push({kind:'request',method:req.method(),allowed});
      if(!allowed)return route.abort('blockedbyclient');
      // Fulfilled from local strings ONLY: no continue(), fetch(), upstream or provider request.
      return route.fulfill({status:200,contentType:'text/html',headers:{'Cache-Control':'no-store'},body:fixtures[req.url()]});
    });
    context.on('page',page=>{if(primaryPage===null)primaryPage=page;else{events.push({kind:'popup',allowed:false});void page.close();}});
    ready=true;
    return {context,events,liveExecutionAuthorized:false,async close(){ready=false;await context.close();}};
  } catch {await context.close();throw Error('Offline interception could not be guaranteed');}
}
module.exports={createOfflineContext};
