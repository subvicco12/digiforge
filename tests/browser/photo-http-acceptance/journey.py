"""Real loopback HTTP; urllib cookie auth; no WP_REST dispatch/mocked transport."""
import json,re,urllib.request,urllib.parse,urllib.error,http.cookiejar,base64,hashlib,zlib,struct,subprocess
from pathlib import Path
ROOT=Path('/workspace/lane-a-photo-http-20261116');private=ROOT/'private';out=ROOT/'receipt';out.mkdir(exist_ok=True)
f=json.loads((private/'fixture.json').read_text());base='http://127.0.0.1:18826';key=f['grant']['session_key'];cases=[]
def png():
 def chunk(k,b):return struct.pack('>I',len(b))+k+b+struct.pack('>I',zlib.crc32(k+b)&0xffffffff)
 rows=b''.join(b'\x00'+b'\x40\x80\xa0\xff'*256 for _ in range(256))
 return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',256,256,8,6,0,0,0))+chunk(b'IDAT',zlib.compress(rows))+chunk(b'IEND',b'')
class Actor:
 def __init__(self,login):
  self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
  self.op.open(base+'/wp-login.php').read();self.op.open(base+'/wp-login.php',urllib.parse.urlencode({'log':login,'pwd':json.loads((private/'credentials.json').read_text())['password'],'wp-submit':'Log In','redirect_to':base+'/editor?df_editor='+key,'testcookie':'1'}).encode()).read()
  html=self.op.open(base+'/editor?df_editor='+key).read().decode();m=re.search('data-nonce="([^"]+)"',html);assert m,html[:300];self.nonce=m[1]
 def api(self,name,action,body=None,deny=False,nonce=None):
  url=base+'/?rest_route='+urllib.parse.quote('/digiforge/v1/customer-editor/'+key+'/'+action)
  req=urllib.request.Request(url,data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json','X-WP-Nonce':self.nonce if nonce is None else nonce})
  try:
   r=self.op.open(req);status=r.status;b=json.load(r)
  except urllib.error.HTTPError as e:status=e.code;b=json.load(e)
  cases.append({'name':name,'status':status,'error':b.get('code') if isinstance(b,dict) else None})
  (out/'http-outcomes.json').write_text(json.dumps(cases,indent=2))
  assert (status>=400) if deny else status==200,(name,status,b)
  return b
buyer=Actor('http_buyer');operator=Actor(f['operator_login'])
# Foreign gets own nonce from authenticated WP admin HTML (editor itself must deny).
foreign=Actor.__new__(Actor);foreign.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));foreign.op.open(base+'/wp-login.php').read();foreign.op.open(base+'/wp-login.php',urllib.parse.urlencode({'log':'http_foreign','pwd':json.loads((private/'credentials.json').read_text())['password'],'redirect_to':base+'/editor?df_editor='+key,'testcookie':'1'}).encode()).read()
html=foreign.op.open(base+'/editor?df_editor='+key).read().decode();m=re.search('data-auth-nonce="([^"]+)"',html);assert m;foreign.nonce=m[1];assert 'data-df-editor' not in html
foreign.api('foreign_view','view',deny=True)
buyer.api('bad_nonce','view',deny=True,nonce='invalid');buyer.api('missing_nonce','view',deny=True,nonce='')
u={'request_key':'http-photo-v1','expected_head':None,'mode':'PHOTO','image_base64':base64.b64encode(png()).decode(),'image_field':'photo','fields':{},'selection':{'x':0,'y':0,'width':256,'height':256},'rights_confirmed':True,'processing_consent':True}
v=buyer.api('buyer_upload','upload',u);sha=v['sha256'];s=buyer.api('buyer_submit','fields',{'expected_head':sha,'submit_for_review':True,'expected_selection_revision':0});sel=s['sha256']
p={'operation':'PHOTO_INPUT_REVIEW','shop_key':'personalized_pod','selection_sha256':sel,'expected_revision':1,'decision':'APPROVED','note':'Synthetic exact input review via HTTP'}
buyer.api('buyer_cannot_operator_review','intake',p,deny=True)
operator.api('operator_view','intake',{'operation':'PHOTO_VIEW','shop_key':'personalized_pod'})
operator.api('operator_input_review','intake',p)
p={'operation':'PHOTO_LAYOUT','shop_key':'personalized_pod','selection_sha256':sel,'expected_revision':1,'template_key':f['input']['template_key'],'template_version':1,'note':'Synthetic retained geometry layout review via HTTP'}
operator.api('operator_layout','publish',p);p.pop('note');p['operation']='PHOTO_PREPARE';render=operator.api('operator_prepare','publish',p);assert render['review_status']=='UNREVIEWED'
inspection=operator.api('operator_inspect_unreviewed_artifact','intake',{'operation':'PHOTO_VIEW','shop_key':'personalized_pod'}); artifact=inspection['render_preview'];data=base64.b64decode(artifact['content_base64']);assert hashlib.sha256(data).hexdigest()==artifact['sha256'];(out/'operator-unreviewed-artifact.png').write_bytes(data)
before=buyer.api('buyer_before_artifact_review','view');assert before['product_preview']['state']=='BLOCKED'
operator.api('operator_artifact_review','publish',{'operation':'PHOTO_APPROVE_RENDER','shop_key':'personalized_pod','selection_sha256':sel,'expected_revision':1,'note':'Synthetic independent operator inspection of exact current artifact'})
view=buyer.api('buyer_reviewed_preview','view');preview=view['product_preview'];assert preview['state']=='AVAILABLE';assert preview['artifact_sha256']==artifact['sha256'];
# Retain actual served preview bytes, not supplied assertion hashes.
for k in ['content_base64','image_base64','preview_base64']:
 if k in preview:
  data=base64.b64decode(preview[k]);(out/'buyer-preview.png').write_bytes(data);assert hashlib.sha256(data).hexdigest()==preview['artifact_sha256']
body={'version_sha256':sha,'context_sha256':preview['context_sha256']};approval=buyer.api('buyer_preference','approve',body);assert approval['artwork_certified'] is False and approval['external_execution_authorized'] is False
import time;time.sleep(1.1)
buyer.api('buyer_preference_replay','approve',body,deny=True)
u['request_key']='http-photo-v2';u['expected_head']=sha;v2=buyer.api('buyer_replacement','upload',u)
buyer.api('old_preference_replay_denied','approve',body,deny=True)
buyer.api('stale_submit_denied','fields',{'expected_head':sha,'submit_for_review':True,'expected_selection_revision':1},deny=True)
p['operation']='PHOTO_PREPARE';operator.api('operator_stale_prepare_denied','publish',p,deny=True)
changed=buyer.api('replacement_preview_blocked','view');assert changed['product_preview']['state']=='BLOCKED'
summary={'transport':'actual loopback HTTP WordPress cookie+nonce','synthetic_only':True,'cases':cases,'render_id':render['id'],'artifact_sha256':artifact['sha256'],'record_output_sha256':render['output_sha256'],'buyer_preference_replay':'409 immutable conflict after timestamp advances; stale replay denied after replacement','preview_fields':list(preview),'holds':{k:approval.get(k) for k in ['artwork_certified','supplier_certified','external_execution_authorized']},'external_attempts':(private/'external-attempts.log').read_text().splitlines() if (private/'external-attempts.log').exists() else []}
(out/'receipt.json').write_text(json.dumps(summary,indent=2));print(json.dumps(summary,indent=2))
