#!/usr/bin/env python3
"""Disposable loopback Shop2 portal regression. Never authenticates to a real provider/site."""
import argparse,base64,hashlib,json,urllib.parse
from pathlib import Path
from playwright.sync_api import sync_playwright

def main():
    args=argparse.ArgumentParser()
    args.add_argument('--base-url',required=True);args.add_argument('--cookies-file',type=Path,required=True)
    args.add_argument('--output',type=Path,required=True);args.add_argument('--chromium',default='/usr/bin/chromium')
    a=args.parse_args();url=urllib.parse.urlsplit(a.base_url)
    if url.hostname not in ['127.0.0.1','localhost'] or url.scheme!='http':raise ValueError('Disposable loopback only')
    a.output.mkdir(parents=True,exist_ok=True);source=Path(__file__).resolve().parents[2]
    fixture=json.loads((source/'tests/fixtures/research/commercial-research.json').read_text())
    events=[];results=[];blocked=[]
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=a.chromium,headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
        context=browser.new_context(viewport={'width':1280,'height':900})
        context.route('**/*',lambda route:route.continue_() if urllib.parse.urlsplit(route.request.url).hostname in ['127.0.0.1','localhost'] else (blocked.append(urllib.parse.urlsplit(route.request.url).hostname),route.abort())[1])
        context.add_cookies([{'name':k,'value':v,'domain':url.hostname,'path':'/','httpOnly':True,'secure':False} for k,v in json.loads(a.cookies_file.read_text()).items()])
        page=context.new_page();page.on('pageerror',lambda e:events.append(str(e)));page.set_default_timeout(30000)
        def form(operation):return page.locator('form:has(input[name="operation"][value="'+operation+'"])').first
        def submit(f):
            with page.expect_navigation(wait_until='domcontentloaded'):f.locator('button').first.click()
            errors=page.locator('.df-notice-error').all_text_contents()
            if errors:raise AssertionError('Portal error: '+str(errors))
        for family,profile,code in [('mug','fl03_mug_11oz','FL-03'),('poster','fl04_poster_8x10','FL-04')]:
            page.goto(a.base_url,wait_until='domcontentloaded')
            f=form('research_import');f.locator('xpath=ancestor::details').locator('summary').click() if not f.is_visible() else None
            research=json.loads(json.dumps(fixture['research']));research['product_family']=family;research['title']='SYNTHETIC '+code+' offline portal journey'
            f.locator('[name=research_json]').fill(json.dumps(research));submit(f)
            f=form('concepts');f.locator('[name=response_json]').fill(json.dumps(fixture['response']));submit(f)
            concepts=page.locator('#df-commercial-research article.df-candidate h4').all_text_contents();assert len(concepts)==3,concepts
            page.locator('#df-commercial-research').screenshot(path=str(a.output/(code+'-three-concepts.png')))
            submit(form('select'))
            f=form('design_approve_concept')
            for check in f.locator('input[type=checkbox]').all():check.check()
            f.locator('[name=note]').fill('SYNTHETIC source review; no genuine commercial approval');submit(f)
            f=form('design_prepare');f.locator('[name=profile]').select_option(profile)
            for key,value in {'layout_notes':'Fixed original engineering vectors; native names remain separate','typography':'Local generic font only; provider parity unverified','font_license_reference':'Existing committed synthetic test art; no commercial rights approval','palette':'#123456','asset_requirements':'Preserve editable first-name second-name and date fields'}.items():f.locator('[name='+key+']').fill(value)
            regions=[{'name':name,'kind':'text','x':600,'y':300+i*200,'width':1200,'height':120} for i,name in enumerate(['first_name','second_name','date'])]
            f.locator('[name=native_regions_json]').fill(json.dumps(regions));submit(f)
            art=(source/('tests/fixtures/design-factory/'+family+'-original.svg')).read_text()
            f=form('design_import');f.locator('[name=svg]').fill(art);f.locator('[name=rights_note]').fill('SYNTHETIC engineering vector rights only; owner review pending');f.locator('[name=source_note]').fill('Exact committed original fixture bytes, not regenerated R5B');submit(f)
            f=form('design_review');f.locator('[name=decision]').select_option('APPROVE');f.locator('[name=note]').fill('SYNTHETIC nine-criterion test decision only')
            for check in f.locator('input[type=checkbox]').all():check.check()
            submit(f)
            f=form('personalization_schema');submit(f)
            f=form('personalization_sample')
            for key,value in {'first_name':'Élodie','second_name':'José','date':'2024-02-29'}.items():f.locator('[name="samples['+key+']"]').fill(value)
            submit(f);panel=page.locator('#df-artwork-personalization');assert 'MANUAL_REVIEW' in panel.inner_text()
            img=panel.locator('img').get_attribute('src');proof=base64.b64decode(img.split(',',1)[1]);assert 'Élodie' in proof.decode()
            f=form('personalization_review');f.locator('[name=decision]').select_option('APPROVE');f.locator('[name=note]').fill('SYNTHETIC local proof test, no provider/production approval');f.locator('[name=confirm]').check();submit(f)
            assert 'Human local-proof decision: APPROVE' in panel.inner_text();assert 'Production authority: NO' in panel.inner_text()
            panel.screenshot(path=str(a.output/(code+'-approved-local-proof.png')))
            fixed=base64.b64decode(page.locator('#df-design-factory > img[alt="Approved-source fixed artwork preview"]').get_attribute('src').split(',',1)[1])
            fixed_hash=hashlib.sha256(fixed).hexdigest();assert fixed_hash in panel.inner_text()
            query=urllib.parse.parse_qs(urllib.parse.urlsplit(page.url).query)
            results.append({'product':code,'profile':profile,'three_concepts':concepts,'workflow_hash':query['df_research_version'][0],'factory_hash':query['df_factory_version'][0],'personalization_hash':query['df_personalization_version'][0],'import_input_svg_sha256':hashlib.sha256(art.encode()).hexdigest(),'fixed_svg_sha256':fixed_hash,'proof_svg_sha256':hashlib.sha256(proof).hexdigest(),'local_review_only':True,'provider_certified':False,'production_authorized':False})
            # Reopen from persisted discovery links without manually pasting any version.
            page.goto(a.base_url,wait_until='domcontentloaded')
            link=page.locator('a[href*="df_research_version='+query['df_research_version'][0]+'"]').first
            page.goto(link.get_attribute('href'),wait_until='domcontentloaded')
            link=page.locator('a[href*="df_factory_version='+query['df_factory_version'][0]+'"]').first
            page.goto(link.get_attribute('href'),wait_until='domcontentloaded')
            link=page.locator('a[href*="df_personalization_version='+query['df_personalization_version'][0]+'"]').first
            page.goto(link.get_attribute('href'),wait_until='domcontentloaded')
            assert 'Human local-proof decision: APPROVE' in panel.inner_text()
            results[-1]['reopened_from_saved_links']=True
            page.set_viewport_size({'width':390,'height':844});panel.screenshot(path=str(a.output/(code+'-mobile.png')));page.set_viewport_size({'width':1280,'height':900})
        page.goto(a.base_url.replace('df_shop=personalized_pod','df_shop=digital'),wait_until='domcontentloaded');assert page.locator('#df-commercial-research').count()==0;assert page.locator('#df-artwork-personalization').count()==0
        assert not events,events
        context.close();browser.close()
    (a.output/'browser-receipt.json').write_text(json.dumps({'journeys':results,'page_errors':events,'blocked_external_hosts':sorted(set(blocked)),'cross_shop_panels_absent':True,'STOP_ALL':True,'external_lock':True,'automation':False,'RELEASE_HOLD':True,'scope':'ACTUAL_DISPOSABLE_WORDPRESS_PORTAL_SYNTHETIC_INPUTS_ONLY'},indent=2)+'\n')
    print(json.dumps({'journeys':len(results),'page_errors':len(events),'products':[x['product'] for x in results]}))
if __name__=='__main__':main()
