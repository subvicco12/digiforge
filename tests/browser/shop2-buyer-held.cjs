'use strict';
// Actual native cookies/nonces, real WordPress HTTP and existing source modules.
// Fixture identity is explicitly local-only; native allocation/dispatch is never supplied.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),net=require('node:net'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {spawn,spawnSync}=require('node:child_process'),{chromium}=require('playwright');
const repo=path.resolve(__dirname,'../..'),support=path.join(__dirname,'shop2-buyer-held');
const digest=p=>crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
function git(args){const r=spawnSync('git',args,{cwd:repo,encoding:'utf8'});assert.equal(r.status,0,'Source identity must be available.');return r.stdout;}
const harnessFiles=['tests/browser/shop2-buyer-held.cjs',...fs.readdirSync(support).sort().map(x=>'tests/browser/shop2-buyer-held/'+x),'tests/wordpress/CustomerPhotoHeldIntegrationTest.php'];
const sourceFiles=git(['ls-files','-z','--','includes','assets','services','digiforge.php','composer.json','composer.lock']).split('\0').filter(Boolean).sort();
const hashes=files=>Object.fromEntries(files.map(p=>[p,digest(path.join(repo,p))]));
function phpRun(php,file,env,args=[]){
 const r=spawnSync(php,[file,...args],{env,encoding:'utf8',timeout:180000,maxBuffer:4*1024*1024});
 fs.writeFileSync(path.join(env.DF_LOCAL_BROWSER_DIR,path.basename(file)+'.log'),r.stdout+'\n'+r.stderr,{mode:0o600});
 if(r.status!==0)throw Error('Disposable '+path.basename(file)+' failed; private diagnostics retained.');
 return r.stdout;
}
async function freePort(){const s=net.createServer();await new Promise((r,j)=>{s.once('error',j);s.listen(0,'127.0.0.1',r);});const p=s.address().port;await new Promise(r=>s.close(r));return p;}
(async()=>{
 const source=process.env.DF_BROWSER_CORE,library=process.env.WP_TESTS_DIR,config=process.env.DF_BROWSER_TEST_CONFIG,php=process.env.DF_BROWSER_PHP||'php';
 assert.equal(process.env.DF_LOCAL_BROWSER_DISPOSABLE,'1','Explicit disposable fixture required.');
 const widths=(process.env.DF_SHOP2_BROWSER_WIDTHS||'390,768,1280').split(',').map(Number);
 assert(widths.length>0&&new Set(widths).size===widths.length&&widths.every(w=>[390,768,1280].includes(w)),'Choose unique supported viewport widths: 390,768,1280.');
 const runtimeChanges=git(['status','--porcelain','--','includes','assets','services','digiforge.php','composer.json','composer.lock']).split('\n').filter(Boolean);
 // This repository does not track a lockfile; the existing CI Composer install
 // generates one. Fingerprint it separately, while rejecting every other edit.
 assert.deepEqual(runtimeChanges.filter(line=>line!=='?? composer.lock'),[],'Runtime source must match HEAD.');
 const lockPath=path.join(repo,'composer.lock'),dependencyLock=fs.existsSync(lockPath)?digest(lockPath):null;
 const baseline=git(['rev-parse','HEAD']).trim(),harnessStart=hashes(harnessFiles),sourceStart=hashes(sourceFiles),harnessUncommitted=git(['status','--porcelain','--',...harnessFiles])!=='';
 for(const p of [path.join(source||'','wp-settings.php'),path.join(library||'','includes/bootstrap.php'),config||''])assert(fs.statSync(p).isFile(),'Existing local core/library/config required.');
 assert(!/-src['"]/.test(fs.readFileSync(path.join(source,'wp-includes/version.php'),'utf8')),'Released WordPress core with built frontend assets required.');
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'digiforge-shop2-browser-'));fs.chmodSync(dir,0o700);fs.writeFileSync(path.join(dir,'disposable.marker'),'LOCAL FIXTURE ONLY\n',{mode:0o600});
 const core=path.join(dir,'core'),content=path.join(dir,'content');fs.mkdirSync(core);
 for(const e of fs.readdirSync(source))if(e==='wp-admin'||e==='wp-includes'||/^wp-.*\.php$|^index\.php$|^xmlrpc\.php$/.test(e))fs.cpSync(path.join(source,e),path.join(core,e),{recursive:true});
 const port=await freePort(),base='http://127.0.0.1:'+port;
 let text=fs.readFileSync(config,'utf8').replace(/define\(\s*'WP_CONTENT_DIR'\s*,[^;]+;/g,'');
 text=text.replace(/define\(\s*'ABSPATH'\s*,[^;]+;/,"if(!defined('ABSPATH'))define('ABSPATH', "+JSON.stringify(core+'/')+");").replace(/\$table_prefix\s*=\s*'[^']*'/,"$table_prefix = 'b"+crypto.randomBytes(2).toString('hex').slice(0,3)+"_'");
 assert(text.includes(core+'/')&&text.includes("$table_prefix = 'b"),'Supported isolated configuration template required.');
 fs.mkdirSync(path.join(content,'mu-plugins'),{recursive:true});fs.mkdirSync(path.join(content,'themes/df-shop2-browser'),{recursive:true});
 text+="\ndefine('WP_HOME',"+JSON.stringify(base)+");define('WP_SITEURL',"+JSON.stringify(base)+");define('WP_CONTENT_DIR',"+JSON.stringify(content)+");define('WP_CONTENT_URL',"+JSON.stringify(base+'/wp-content')+");define('AUTOMATIC_UPDATER_DISABLED',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_ENVIRONMENT_TYPE','local');define('DIGIFORGE_CREDENTIAL_KEY',str_repeat('synthetic-browser-only-',2));\n";
 fs.writeFileSync(path.join(dir,'test-config.php'),text,{mode:0o600});
 fs.writeFileSync(path.join(core,'wp-config.php'),"<?php define('DISABLE_WP_CRON',true);require_once "+JSON.stringify(path.join(dir,'test-config.php'))+";require ABSPATH.'wp-settings.php';",{mode:0o600});
 fs.writeFileSync(path.join(content,'mu-plugins/fixture.php'),'<?php require '+JSON.stringify(path.join(support,'guard.php'))+';');
 fs.writeFileSync(path.join(content,'themes/df-shop2-browser/style.css'),'/*\nTheme Name: Disposable Shop2 Browser\n*/');
 fs.writeFileSync(path.join(content,'themes/df-shop2-browser/index.php'),'<?php $view=isset($_GET["df_editor"])?do_shortcode("[digiforge_personalization_editor]"):do_shortcode("[digiforge_admin_portal]");?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head();?></head><body <?php body_class();?>><?php echo $view;wp_footer();?></body></html>');
 const env={...process.env,DF_LOCAL_BROWSER_DIR:dir,DF_LOCAL_BROWSER_REPO:repo};
 let server,browser,success=false,requests=0,blocked=0,checks=0;const assetErrors=[];
 try{
  phpRun(php,path.join(support,'setup.php'),env);const fixture=JSON.parse(fs.readFileSync(path.join(dir,'journeys.json')));
  const log=fs.openSync(path.join(dir,'server.log'),'a');server=spawn(php,['-S','127.0.0.1:'+port,'-t',core,path.join(support,'router.php')],{env,stdio:['ignore',log,log]});fs.closeSync(log);
  for(let i=0;i<100;i++){assert.equal(server.exitCode,null,'Disposable server must stay alive.');try{await new Promise((r,j)=>{const s=net.connect(port,'127.0.0.1',()=>{s.end();r();});s.once('error',j);});break;}catch{if(i===99)throw Error('Disposable server unavailable.');await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({executablePath:process.env.DF_BROWSER_EXECUTABLE||'/usr/bin/chromium',headless:true,args:process.env.DF_BROWSER_NO_SANDBOX==='1'?['--no-sandbox']:[]});
  async function context(width){const c=await browser.newContext({viewport:{width,height:900},isMobile:width===390,hasTouch:width===390,serviceWorkers:'block'});c.setDefaultTimeout(30000);c.setDefaultNavigationTimeout(30000);c.on('response',r=>{if(new URL(r.url()).pathname.startsWith('/df-assets/')&&r.status()!==200)assetErrors.push({asset:path.basename(new URL(r.url()).pathname),status:r.status()});});await c.route('**/*',async r=>{const u=new URL(r.request().url());requests++;if(u.origin!==base||!['GET','POST'].includes(r.request().method())){blocked++;return r.abort();}return r.continue();});return c;}
  async function login(c,actor,target){const p=await c.newPage();await p.goto(base+'/wp-login.php?redirect_to='+encodeURIComponent(target));await p.locator('#user_login').fill(actor.login);await p.locator('#user_pass').fill(actor.password);await Promise.all([p.waitForURL(u=>u.pathname!=='/wp-login.php'),p.locator('#wp-submit').click()]);assert((await c.cookies()).some(x=>x.name.startsWith('wordpress_logged_in_')));checks++;return p;}
  async function rest(p,action,body){return p.evaluate(async({action,body})=>{const root=document.querySelector('[data-df-editor],[data-df-photo-review]');const r=await fetch(root.dataset.endpoint+action,{method:body===undefined?'GET':'POST',headers:{'X-WP-Nonce':root.dataset.nonce,'Content-Type':'application/json'},...(body===undefined?{}:{body:JSON.stringify(body)})});return {status:r.status,data:await r.json()};},{action,body});}
  async function clickResponse(p,selector,action){const response=p.waitForResponse(r=>r.request().method()==='POST'&&r.url().endsWith('/'+action));await p.locator(selector).click();const r=await response;const data=await r.json();assert.equal(r.status(),200,action+': '+(data.code||data.message||'denied'));checks++;return data;}
  async function localAction(label){await operator.getByRole('button',{name:label,exact:true}).last().click();await operator.locator('[name=df_confirm_review]').check();const payload=await operator.locator('form').evaluate(form=>new URLSearchParams(new FormData(form)).toString());await operator.getByRole('button',{name:'Confirm local action',exact:true}).click();await operator.getByRole('link',{name:'Return to this order',exact:true}).waitFor();const replay=await operator.evaluate(async payload=>{const r=await fetch('/wp-admin/admin-post.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:payload});const doc=new DOMParser().parseFromString(await r.text(),'text/html');return {alert:doc.querySelector('[role=alert]')?.textContent,success:doc.body.textContent.includes('Return to this order')};},payload);assert(replay.alert&&!replay.success,'Replayed operator confirmation must deny a second effect.');await operator.getByRole('link',{name:'Return to this order',exact:true}).click();checks+=2;}
  const operatorContext=await context(1280),operator=await operatorContext.newPage();
  await operator.goto(base+'/?df_auth=login&redirect_to='+encodeURIComponent(base+'/?df_view=orders&df_shop=personalized_pod'));
  await operator.getByLabel('Email address').fill(fixture.operator.email);
  await operator.getByRole('button',{name:'Email my sign-in code'}).click();
  await operator.getByLabel('Email verification code').waitFor();
  assert(fs.existsSync(path.join(dir,'otp.json')),'Actual local email challenge must have been issued.');
  await operator.getByLabel('Email verification code').fill(JSON.parse(fs.readFileSync(path.join(dir,'otp.json'))).code);
  await operator.getByRole('button',{name:'Verify and sign in'}).click();
  await operator.waitForURL(u=>u.searchParams.get('df_view')==='orders');
  assert.deepEqual(assetErrors,[],'Initial portal styles/scripts must load before expensive workflow acceptance.');
  assert((await operatorContext.cookies()).some(x=>x.name.includes('digiforge_portal')),'Actual portal sign-in must establish its session.');checks++;
  const journeys=[],databaseEvidence=[],proofs=[];
  async function proof(page,label){const output=process.env.DF_SHOP2_BROWSER_EVIDENCE;if(!output)return;const file=path.join(path.dirname(output),label+'.png');assert(!fs.existsSync(file),'Evidence paths must be fresh.');await page.screenshot({path:file,fullPage:true});proofs.push({file:path.basename(file),sha256:digest(file)});}
  async function verify(intent,expectation,job=0,preview=''){fs.writeFileSync(path.join(dir,'operator-cookies.json'),JSON.stringify(await operatorContext.cookies()),{mode:0o600});const raw=phpRun(php,path.join(support,'verify.php'),env,[String(intent),expectation,String(job),preview]);const result=JSON.parse(raw.trim().split('\n').at(-1));databaseEvidence.push(result);checks++;return result;}
  for(const f of fixture.journeys.filter(f=>widths.includes(f.width))){
   const url=base+'/?df_editor='+encodeURIComponent(f.session),anon=await context(f.width),p=await anon.newPage();await p.goto(url);assert.match(await p.locator('body').innerText(),/unavailable|Sign in|sign in/i);checks++;await anon.close();
   const foreign=await context(f.width),wrong=await login(foreign,fixture.foreign,url);assert.match(await wrong.locator('body').innerText(),/unavailable/i);checks++;await foreign.close();
   const c=await context(f.width),buyer=await login(c,f.buyer,url);await buyer.locator('[data-status]').filter({hasText:/Upload your artwork to begin\.|Current artwork loaded\./}).waitFor();checks++;
   const endpoint=await buyer.locator('[data-df-editor]').getAttribute('data-endpoint');
   const denied=await buyer.evaluate(async endpoint=>{const r=await fetch(endpoint+'view');return r.status;},endpoint);assert([401,403].includes(denied));checks++;
   assert.equal(await buyer.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false);checks++;
   await proof(buyer,'buyer-'+f.width+'-editor');
   assert.equal(await buyer.locator('[data-submit-photos]').isDisabled(),true);checks++;
   await buyer.locator('[data-upload]').click();await buyer.locator('[data-status]').filter({hasText:'Choose an image and confirm permission and processing consent.'}).waitFor();checks++;
   await buyer.locator('[name=method][value=PHOTO]').check();
   await buyer.locator('[data-file]').setInputFiles({name:'local-photo.png',mimeType:'image/png',buffer:Buffer.from(f.request.image_base64,'base64')});
   await buyer.locator('[data-status]').filter({hasText:'Image ready.'}).waitFor();
   // Keyboard-accessible numeric crop; no canvas coordinate injection or renderer mock.
   for(const [key,value]of Object.entries({x:16,y:16,width:224,height:224})){await buyer.locator('[data-coordinate='+key+']').fill(String(value));await buyer.locator('[data-coordinate='+key+']').press('Tab');}
   await buyer.locator('[data-rights]').check();await buyer.locator('[data-consent]').check();
   await clickResponse(buyer,'[data-upload]','upload');
   await buyer.locator('[data-status]').filter({hasText:'Current artwork loaded.'}).waitFor();
   const uploaded=await rest(buyer,'view');assert.equal(uploaded.status,200);assert.match(uploaded.data.version_sha256,/^[a-f0-9]{64}$/);assert.equal(uploaded.data.images.photo.kind,'PHOTO');assert.equal(await buyer.locator('[data-original]').isVisible(),true);assert.equal(await buyer.locator('[data-cutout]').isVisible(),true);checks+=5;
   assert.deepEqual(uploaded.data.images.photo.crop.rectangle_px,{x:16,y:16,width:224,height:224});assert.equal(uploaded.data.images.photo.crop.external_execution_authorized,false);checks+=2;
   assert.notEqual(uploaded.data.product_preview.state,'AVAILABLE');assert.equal(await buyer.locator('[data-approve]').isDisabled(),true);checks+=2;
   if(journeys.length){const old=journeys.at(-1);assert.notEqual(uploaded.data.version_sha256,old.editor_version_sha256);const stale=await rest(buyer,'approve',{version_sha256:old.editor_version_sha256,context_sha256:old.preview_context_sha256});assert.equal(stale.status,409);assert.equal(stale.data.code,'customer_editor_stale_revision');await verify(old.intent_id,'STALE');checks+=3;}
   await clickResponse(buyer,'[data-submit-photos]','fields');await buyer.locator('[data-photo-selection]').filter({hasText:'UNREVIEWED'}).waitFor();
   await operator.setViewportSize({width:f.width,height:900});await operator.goto(url);await operator.locator('[data-review-status]').filter({hasText:'UNREVIEWED'}).waitFor();
   await operator.locator('[data-review-template]').selectOption(f.template+'@1');
   await operator.locator('[data-review-note]').fill('Offline browser fixture: inspected exact current cropped photo. No production approval.');
   await operator.locator('[data-review-confirm]').check();await clickResponse(operator,'[data-review-input]','intake');await operator.locator('[data-review-status]').filter({hasText:'APPROVED'}).waitFor();
   await operator.locator('[data-review-confirm]').check();await clickResponse(operator,'[data-photo-layout]','publish');
   await clickResponse(operator,'[data-prepare-photo]','publish');await operator.locator('[data-review-art]').waitFor({state:'visible'});
   const operatorArtifact=await operator.locator('[data-review-art]').getAttribute('src');
   await operator.locator('[data-render-confirm]').check();await clickResponse(operator,'[data-approve-photo-render]','publish');
   await buyer.locator('[data-refresh]').click();await buyer.locator('[data-product]').waitFor({state:'visible'});
   const reviewed=await rest(buyer,'view');assert.equal(reviewed.status,200);assert.equal(reviewed.data.product_preview.state,'AVAILABLE');
   const previewBytes=Buffer.from(reviewed.data.product_preview.content_base64,'base64');assert(previewBytes.length>0);checks+=3;
   assert.equal(operatorArtifact.split(',')[1],reviewed.data.product_preview.content_base64,'Buyer preview must retain the exact operator-reviewed local artwork bytes.');checks++;
   await clickResponse(buyer,'[data-approve]','approve');await buyer.locator('[data-product-status]').filter({hasText:'Your digital preference is recorded.'}).waitFor();
   assert.equal(await buyer.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false);checks++;
   await proof(buyer,'buyer-'+f.width+'-approved-digital-preview');
   await operator.goto(base+'/?df_view=orders&df_shop=personalized_pod&df_order_id='+f.order);
   await operator.locator('[name=base_production_cost]').fill('10');await operator.locator('[name=shipping_estimate]').fill('4');
   await localAction('Preview DRAFT fulfillment plan');await localAction('Preview local fulfillment plan review entry');await localAction('Preview local fulfillment plan approval');await localAction('Preview BLOCKED fulfillment preparation intent');
   await operator.getByRole('link',{name:'Inspect held preparation',exact:true}).first().click();await operator.getByText('HELD_FULFILLMENT_PREPARED',{exact:true}).waitFor();
   const intent=Number(new URL(operator.url()).searchParams.get('df_intent_id'));assert(intent>0);
   await operator.getByRole('button',{name:'Preview BLOCKED held-job metadata',exact:true}).click();await operator.locator('[name=df_confirm_review]').check();await operator.getByRole('button',{name:'Confirm local held-job action',exact:true}).click();await operator.getByRole('link',{name:'Inspect this order’s held-job metadata',exact:true}).click();await operator.getByRole('heading',{name:/Held job #[0-9]+ \/ BLOCKED/}).first().waitFor();checks++;
   assert.equal(await operator.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,'Styled operator held-job inspection must fit the viewport.');checks++;
   const job=Number(new URL(operator.url()).searchParams.get('df_held_job_id')),previewSha=crypto.createHash('sha256').update(previewBytes).digest('hex');assert(job>0);await verify(intent,'POSITIVE',job,previewSha);
   await proof(operator,'operator-'+f.width+'-blocked-held-job');
   journeys.push({width:f.width,intent_id:intent,editor_version_sha256:reviewed.data.version_sha256,selection_sha256:reviewed.data.photo_selection.sha256,preview_context_sha256:reviewed.data.product_preview.context_sha256,preview_sha256:crypto.createHash('sha256').update(previewBytes).digest('hex'),buyer_preference_recorded:true,approved_local_plan_and_blocked_intent:true,external_execution_authorized:false});
   if(f===fixture.journeys.filter(f=>widths.includes(f.width)).at(-1)){
    await operator.goto(url);await operator.locator('[data-review-status]').filter({hasText:'APPROVED'}).waitFor();const revoked=await rest(operator,'revoke',{access_sha256:f.access_sha256});assert.equal(revoked.status,200);const denied=await rest(buyer,'view');assert([403,409].includes(denied.status));assert.equal(denied.data.code,'customer_editor_access_revoked');await verify(intent,'REVOKED');checks+=3;
   }
   await c.close();
  }
  assert.equal(blocked,0);assert(!fs.existsSync(path.join(dir,'violations.jsonl')),'No outbound HTTP or safety violation is allowed.');
  assert.deepEqual(assetErrors,[],'Every requested application stylesheet/script must actually load.');
  assert.equal(journeys.length,widths.length,'Every requested viewport must complete its actual workflow.');assert.deepEqual(journeys.map(j=>j.width).sort(),[...widths].sort());
  assert.equal(git(['rev-parse','HEAD']).trim(),baseline);assert.deepEqual(hashes(harnessFiles),harnessStart,'Harness bytes must remain unchanged during execution.');assert.deepEqual(hashes(sourceFiles),sourceStart,'Runtime source bytes must remain unchanged during execution.');
  assert.equal(fs.existsSync(lockPath)?digest(lockPath):null,dependencyLock,'Resolved dependency lock bytes must remain unchanged during execution.');
  const result={schema:'shop2-real-http-acceptance-v1',baseline_source_sha:fixture.source_sha,test_harness_uncommitted:harnessUncommitted,runtime_git_dirty:false,harness_sha256:harnessStart,runtime_tree_sha256:crypto.createHash('sha256').update(JSON.stringify(sourceStart)).digest('hex'),dependency_lock_sha256:dependencyLock,dependency_lock_tracked:sourceFiles.includes('composer.lock'),phase:'BUYER_EDITOR_REVIEW_APPROVED_PLAN_BLOCKED_HELD_JOB',checks,requests,viewports:widths,journeys,databaseEvidence,proofs,transport:'REAL_WORDPRESS_HTTP',backend_mocked:false,supplier_evidence:'DISPOSABLE_FIXTURE',buyer_identity_authority:fixture.buyer_identity_authority,native_order_version:null,native_allocation_execution_authorized:false,external_requests:blocked,production_activation_authorized:false};
  const output=process.env.DF_SHOP2_BROWSER_EVIDENCE;if(output)fs.writeFileSync(output,JSON.stringify(result,null,2)+'\n');
  console.log(JSON.stringify(result));success=true;
 }catch(e){if(browser){let i=0;for(const c of browser.contexts())for(const p of c.pages())try{fs.writeFileSync(path.join(dir,'failure-'+(i++)+'.html'),await p.content(),{mode:0o600});}catch{}}console.error('Private disposable diagnostics: '+dir);throw e;}
 finally{if(browser)await browser.close();if(server&&server.exitCode===null){server.kill('SIGTERM');await new Promise(r=>server.once('exit',r));}if(success){phpRun(php,path.join(__dirname,'authenticated-local/cleanup.php'),env);fs.rmSync(dir,{recursive:true,force:true});}}
})().catch(e=>{console.error(e.message);process.exit(1);});
