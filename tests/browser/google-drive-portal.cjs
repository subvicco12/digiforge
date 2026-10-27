const {chromium}=require('playwright'),fs=require('fs'),path=require('path');
(async()=>{
 const dir=process.argv[2],css=fs.readFileSync(path.join(__dirname,'../../assets/portal.css'),'utf8');
 const names=['ready','held','unknown','done','foreign','unavailable'];for(const n of names)if(!fs.statSync(path.join(dir,n+'.html')).size)throw Error('Missing native fixture: '+n);
 const browser=await chromium.launch({headless:true});let checks=0,requests=0;
 try{for(const width of [390,768,1280]){const page=await browser.newPage({viewport:{width,height:900}});await page.route('**/*',r=>{requests++;return r.abort();});
  const load=async n=>page.setContent('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}'+css+'</style>'+fs.readFileSync(path.join(dir,n+'.html'),'utf8'));
  await load('ready');let form=page.locator('form').filter({has:page.getByRole('button',{name:'Authorize source-bound transfer',exact:true})});
  await form.evaluate(n=>n.addEventListener('submit',e=>{e.preventDefault();window.__submitted=Object.fromEntries(new FormData(n));}));await form.locator('input[type=checkbox]').check();await form.locator('input[type=checkbox]').focus();await page.keyboard.press('Tab');
  if(!await form.locator('button').evaluate(n=>n===document.activeElement))throw Error('Keyboard order');checks++;await page.keyboard.press('Enter');const data=await page.evaluate(()=>window.__submitted);
  if(data.action!=='digiforge_google_queue'||data.plan_id!=='a'.repeat(64)||data.df_confirm_google_action!=='1'||!data._wpnonce)throw Error('Exact owner payload missing');checks++;
  await load('unknown');if(!await page.getByRole('button',{name:'Authorize source-bound transfer',exact:true}).isDisabled())throw Error('Unknown create can restart');checks++;
  const recover=page.locator('form').filter({has:page.getByRole('button',{name:'Reconcile original upload by read only',exact:true})});if(await recover.locator('input[name=reconcile]').inputValue()!=='original_upload')throw Error('Wrong recovery role');checks++;
  for(const n of ['held','done']){await load(n);if(await page.locator('button:not([disabled])').count())throw Error('Held/completed action enabled');checks++;}
  await load('foreign');if(await page.locator('form').count())throw Error('Foreign controls leaked');checks++;
  await load('unavailable');if(!await page.getByText(/evidence unavailable/).count()||await page.locator('form').count())throw Error('Unavailable became empty/ready');checks++;
  for(const n of names){await load(n);if(await page.evaluate(()=>document.documentElement.scrollWidth)>width+1)throw Error('Viewport overflow '+n+' '+width);checks++;const missing=await page.evaluate(()=>[...document.querySelectorAll('input:not([type=hidden]),button')].filter(n=>!(n.labels?.length||n.getAttribute('aria-label')||n.tagName==='BUTTON'&&n.textContent.trim())).length);if(missing)throw Error('Unlabelled control');checks++;}
  await page.close();
 }if(requests)throw Error('Unexpected network requests '+requests);console.log(JSON.stringify({status:'PASS',checks,widths:[390,768,1280],unexpected_network_requests:requests,evidence:'native_WordPress_rendered_synthetic_fixtures_plus_separate_native_handler_tests_NOT_installed_site_or_authenticated_HTTP_browser_acceptance'}));}finally{await browser.close();}
})().catch(e=>{console.error(e.message);process.exit(1);});
