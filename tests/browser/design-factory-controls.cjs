// DOM interaction coverage using real WP-rendered HTML with setContent; no authenticated server claim.
const {chromium}=require('playwright');
const fs=require('fs');const assert=require('node:assert/strict');const path=require('path');
(async()=>{const evidence=process.env.PORTAL_CONTROLS_EVIDENCE;const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});let checks=0;
try{const page=await browser.newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true});const html=fs.readFileSync(path.join(evidence,'specification.html'),'utf8');await page.setContent('<meta name="viewport" content="width=device-width, initial-scale=1">'+html);const js=path.join(__dirname,'../../assets/design-factory-controls.js');if(fs.existsSync(js))await page.addScriptTag({path:js});const css=path.join(__dirname,'../../assets/design-factory-controls.css');if(fs.existsSync(css))await page.addStyleTag({path:css});
const regions=page.locator('[data-df-regions]').first();assert.equal(await regions.locator('[data-df-region-rows] > fieldset').count(),1);checks++;
await regions.locator('[data-df-region-add]').click();assert.equal(await regions.locator('[data-df-region-rows] > fieldset').count(),2);checks++;
assert.equal(await page.evaluate(()=>document.activeElement.name),'native_regions[1][name]');checks++;await page.keyboard.type('photo');assert.equal(await regions.locator('input[name="native_regions[1][name]"]').inputValue(),'photo');checks++;
await regions.locator('[data-df-region-remove]').first().click();assert.equal(await regions.locator('[data-df-region-rows] > fieldset').count(),1);assert.equal(await regions.locator('input[name="native_regions[0][name]"]').inputValue(),'photo');checks++;
for(let i=1;i<10;i++)await regions.locator('[data-df-region-add]').click();assert.equal(await regions.locator('[data-df-region-rows] > fieldset').count(),10);assert.equal(await regions.locator('[data-df-region-add]').isDisabled(),true);checks++;
assert.equal(await regions.locator('input[type=number]').first().getAttribute('inputmode'),'numeric');checks++;
assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);checks++;
await page.setContent('<meta name="viewport" content="width=device-width, initial-scale=1">'+fs.readFileSync(path.join(evidence,'artwork.html'),'utf8'));if(fs.existsSync(js))await page.addScriptTag({path:js});if(fs.existsSync(css))await page.addStyleTag({path:css});const png=page.locator('input[name="artwork_pngs[]"]');await png.setInputFiles(Array.from({length:4},(_,i)=>({name:`test${i}.png`,mimeType:'image/png',buffer:Buffer.from('invalid png')})));assert.match(await page.locator('[data-df-upload-status]').textContent(),/three|3/i);assert.notEqual(await png.evaluate(el=>el.validationMessage),'');checks++;
await png.setInputFiles([]);assert.equal(await png.evaluate(el=>el.validationMessage),'');checks++;
await page.screenshot({path:path.join(evidence,'mobile-controls.png'),fullPage:true});console.log(`PASS ${checks} DOM/browser assertions, mocked page content; 390px mobile viewport`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
