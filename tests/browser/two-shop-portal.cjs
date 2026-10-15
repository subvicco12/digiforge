/* Synthetic native WordPress output only; not deployed/browser-authenticated HTTP E2E. */
const {chromium}=require('playwright');
const fs=require('fs'),path=require('path');
(async()=>{
 const dir=process.argv[2],css=fs.readFileSync(path.join(__dirname,'../../assets/portal.css'),'utf8');
 const browser=await chromium.launch({executablePath:process.env.DF_BROWSER_EXECUTABLE||'/usr/bin/chromium',headless:true,args:process.env.DF_BROWSER_NO_SANDBOX==='1'?['--no-sandbox']:[]});
 let checks=0,requests=0;
 try{for(const width of [390,768,1280]){
  const page=await browser.newPage({viewport:{width,height:900}});await page.route('**/*',r=>{requests++;return r.abort();});
  for(const [file,shop] of [['digital','digital'],['personalized','personalized_pod']]){
   await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}${css}</style>${fs.readFileSync(path.join(dir,file+'.html'),'utf8')}`);
   const select=page.getByRole('combobox',{name:'Shop',exact:true});if(await select.inputValue()!==shop)throw Error('Wrong selected shop');checks++;
   const options=await select.locator('option').evaluateAll(ns=>ns.map(n=>n.value));if(JSON.stringify(options)!==JSON.stringify(['digital','personalized_pod']))throw Error('Deferred/global scope exposed');checks++;
   await select.focus();if(!await select.evaluate(n=>n===document.activeElement))throw Error('Shop selector keyboard access');checks++;
   if(!await page.getByRole('navigation',{name:'DigiForge navigation'}).isVisible())throw Error('Navigation unavailable');checks++;
   if(file==='personalized'){
    if(!await page.getByRole('heading',{name:'Phase-1 Personalized POD operations',exact:true}).isVisible())throw Error('Scoped workflow unavailable');checks++;
    const field=page.getByLabel('Template identity',{exact:true});await field.focus();await page.keyboard.type('synthetic-local-template');if(await field.inputValue()!=='synthetic-local-template')throw Error('Keyboard workflow unavailable');checks++;
    if(await page.getByRole('heading',{name:'Master 500 v2 ingestion readiness',exact:true}).count())throw Error('Global catalog exposed');checks++;
   }
   if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2))throw Error('Viewport overflow');checks++;
  }await page.close();
 }if(requests)throw Error('Unexpected network');console.log(JSON.stringify({checks,requests,widths:[390,768,1280],evidence:'SYNTHETIC_NATIVE_FIXTURE'}));}
 finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
