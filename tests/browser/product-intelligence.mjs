// Usage: node tests/browser/product-intelligence.mjs /absolute/panel-fixture.html
import { createRequire } from 'node:module';
const { chromium }=createRequire(import.meta.url)('playwright');
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const path=process.argv[2];
assert(path?.startsWith('/'),'Provide the authenticated WordPress test fixture HTML path');
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
let checks=0;
try {
 for(const width of [390,768,1280]){
  const page=await browser.newPage({viewport:{width,height:900}});
  await page.setContent(await readFile(path,'utf8'));
  assert(await page.getByRole('heading',{name:'Shop 2 · Printify Product Intelligence'}).isVisible());checks++;
  assert.equal(await page.locator('script').count(),0);checks++;
  assert(await page.getByLabel('Evidence section').isVisible());checks++;
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Page overflows at ${width}px`);checks++;
  assert(await page.locator('.df-pi-card').count()>0);checks++;
  const controls=page.getByText('Prepare or resume provider discovery',{exact:true});
  if(await controls.count()){
   await controls.click();
   assert(await page.getByLabel('Blueprint IDs from this snapshot (comma-separated)').isVisible());checks++;
   assert(await page.getByRole('button',{name:'Collect / resume one step'}).isDisabled());checks++;
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));checks++;
  }
  await page.screenshot({path:path.replace(/\.html$/,`-${width}.png`),fullPage:true});
  await page.close();
 }
} finally {await browser.close();}
console.log(`${checks} browser checks passed at 390, 768, 1280px`);
