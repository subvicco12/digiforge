#!/usr/bin/env python3
"""Real supplier-mounted loopback HTTP held exports. Synthetic core setup; never a live provider."""
import argparse,hashlib,json,os,struct,subprocess,urllib.parse,zlib
from pathlib import Path
from playwright.sync_api import sync_playwright

def main():
 a=argparse.ArgumentParser();a.add_argument('--private-fixture',type=Path,required=True);a.add_argument('--journeys',type=Path,required=True);a.add_argument('--output',type=Path,required=True);a.add_argument('--php',required=True);a.add_argument('--source-sha',required=True);args=a.parse_args()
 root=Path(__file__).resolve().parents[2];private=args.private_fixture;s=json.loads((private/'session.json').read_text());base=s['base'];origin=urllib.parse.urlsplit(base)
 if origin.scheme!='http' or origin.hostname!='127.0.0.1' or s.get('synthetic')is not True:raise RuntimeError('Disposable loopback fixture only')
 if subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()!=args.source_sha:raise RuntimeError('Wrong source head')
 def fingerprints():return {n:hashlib.sha256((root/n).read_bytes()).hexdigest()for n in subprocess.check_output(['git','ls-files'],cwd=root,text=True).splitlines()if(root/n).is_file()}
 before=fingerprints();args.output.mkdir(exist_ok=True,parents=True);prior=json.loads(args.journeys.read_text());results=[];blocked=[];errors=[]
 def png(i):
  def chunk(t,b):return struct.pack('!I',len(b))+t+b+struct.pack('!I',zlib.crc32(t+b)&0xffffffff)
  return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!IIBBBBB',8,8,8,2,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+bytes([i,34,51])*8)*8))+chunk(b'IEND',b'')
 with sync_playwright()as pw:
  browser=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);c=browser.new_context(accept_downloads=True,viewport={'width':1280,'height':900});c.add_cookies([{'name':k,'value':v,'url':base}for k,v in json.loads((private/'cookies.json').read_text()).items()]);c.route('**/*',lambda q:q.continue_()if urllib.parse.urlsplit(q.request.url).netloc==origin.netloc else(blocked.append(urllib.parse.urlsplit(q.request.url).hostname),q.abort())[1]);page=c.new_page();page.on('pageerror',lambda e:errors.append(str(e)));page.set_default_timeout(120000)
  def form(op):return page.locator('form:has(input[name="operation"][value="'+op+'"])').first
  def submit(f,button=None):
   with page.expect_navigation(wait_until='domcontentloaded'):(button or f.locator('button').first).click()
   q=urllib.parse.parse_qs(urllib.parse.urlsplit(page.url).query);assert not q.get('df_workflow_error'),q.get('df_workflow_error');return q
  for j in prior['journeys']:
   mug=j['product']=='FL-03';family='mug'if mug else'poster';q={'df_view':'pod_personalized','df_shop':'personalized_pod','df_supplier_workflow':j['supplier_workflow_hash'],'df_research_version':j['workflow_hash'],'df_factory_version':j['factory_hash'],'df_personalization_version':j['personalization_hash']};url=s['portal']+'&'+urllib.parse.urlencode(q);page.goto(url,wait_until='domcontentloaded');assert 'Human local-proof decision: APPROVE'in page.locator('#df-artwork-personalization').inner_text()
   env=dict(os.environ,DF_SHOP2_DISPOSABLE='1');cp=subprocess.run([args.php,str(private/'business-fixture.php'),json.dumps(j)],env=env,capture_output=True,text=True,check=True);ids=json.loads(cp.stdout)
   for i in range(1,6):
    f=form('mockup_import');f.locator('[name=note]').fill('SYNTHETIC engineering pixels; no commercial mockup or supplier approval '+str(i));f.locator('[name=mockup_png]').set_input_files({'name':'synthetic.png','mimeType':'image/png','buffer':png(i)});submit(f)
   e=json.loads((root/'tests/fixtures/product-packages'/('fl-03.json'if mug else'fl-04.json')).read_text());bp,provider,variant,w,h=(5,7,9,2475,1155)if mug else(6,8,10,2418,3018)
   e['template']={'template_id':'supplier-'+family,'template_version':1,'supplier':'printify','provider_blueprint_id':bp,'provider_id':provider,'variant_ids':[variant],'personalization_pipeline':'PRINTIFY_NATIVE','personalization_engine':'MANUAL_DECLARATION','template_status':'DRAFT','print_areas':[{'position':'front','decoration_method':'sublimation','width_px':w,'height_px':h}]}
   e['specification']['native_fields']=[{'layer':name,'type':'TEXT','required':True,'max_characters':10 if name=='date' else 20,'provider_field_id':None}for name in ['first_name','second_name','date']]
   e['native_fields']=[{'question_type':'text_input','question_text':name,'required':True,'max_allowed_characters':10 if name=='date' else 20}for name in ['first_name','second_name','date']]
   f=form('package_prepare');f.locator('[name=evidence_json]').fill(json.dumps(e))
   for name in ['business_integration_id','business_mapping_id','business_asset_spec_id']:f.locator('[name='+name+']').fill(ids[name])
   q=submit(f,f.locator('button[value=import]'));package=q['df_package_version'][0];f=form('package_decide');f.locator('[name=decision]').select_option('APPROVE');f.locator('[name=note]').fill('SYNTHETIC exact proof/business-bound held package only');f.locator('[name=confirm]').check();submit(f)
   f=form('package_export');f.locator('[name=confirm]').check();body=dict(f.locator('input').evaluate_all('ns=>ns.map(n=>[n.name,n.type==="checkbox"?"1":n.value])'))
   with page.expect_download()as download:f.locator('button').click()
   target=args.output/(j['product']+'-HELD.json');download.value.save_as(target);raw=target.read_bytes();out=json.loads(raw);bound=out['proof_business_binding'];proof=bound['personalization_intake'];assert proof['versions']['proof_hash']==j['personalization_hash'];assert proof['intake_hash']==j['intake_hash'];assert bound['business']['product_version_id']==ids['product_version_id'];assert str(bound['business']['selectors']['provider_mapping_id'])==ids['business_mapping_id'];assert out['package_hash']==out['immutable_package_record_sha256']==package;assert out['release_hold']is True and out['external_execution_authorized']is False
   again=c.request.post(base+'/wp-admin/admin-post.php',form=body,max_redirects=0);assert again.status==200 and again.body()==raw
   negatives=[]
   for name,changes in [('nonexistent_proof',{'personalization_hash':'f'*64}),('substituted_review',{'review_hash':'a'*64}),('wrong_business',{'business_mapping_id':str(int(ids['business_mapping_id'])+9999)}),('cross_shop',{'shop_key':'digital'}),('invalid_nonce',{'_df_workflow_nonce':'invalid'})]:
    result=c.request.post(base+'/wp-admin/admin-post.php',form=dict(body,**changes),max_redirects=0);assert result.status==403 if name=='invalid_nonce'else result.status in [302,303],(name,result.status);assert 'application/json'not in result.headers.get('content-type','');negatives.append({'case':name,'status':result.status,'denied':True})
   results.append({'product':j['product'],'package_hash':package,'held_sha256':hashlib.sha256(raw).hexdigest(),'proof_hash':proof['versions']['proof_hash'],'schema_hash':proof['versions']['schema_hash'],'review_hash':proof['versions']['review_hash'],'intake_hash':proof['intake_hash'],'business_ids':ids,'byte_identical_repeat':True,'negative_http':negatives,'synthetic_only':True,'external_execution_authorized':False});page.screenshot(path=str(args.output/(j['product']+'-held.png')),full_page=True)
  browser.close()
 assert before==fingerprints(),'Source changed during HTTP acceptance';assert not blocked,blocked;assert not errors,errors
 receipt={'source_sha':args.source_sha,'wordpress':'7.1.4','scope':'ACTUAL_SUPPLIER_MOUNTED_HTTP_SYNTHETIC_CORE_APPROVALS','source_unchanged':True,'journeys':results,'page_errors':errors,'blocked_external_hosts':blocked,'STOP_ALL':True,'external_lock':True,'automation':False,'RELEASE_HOLD':True,'production_acceptance':False};(args.output/'HTTP_RECEIPT.json').write_text(json.dumps(receipt,indent=2)+'\n');print(json.dumps({'journeys':len(results),'http_denials':sum(len(x['negative_http'])for x in results)}))
if __name__=='__main__':main()
