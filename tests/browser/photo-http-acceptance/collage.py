from pathlib import Path
exec(Path(__file__).with_name('journey.py').read_text().split("buyer=Actor('http_buyer')")[0])
out=ROOT/'receipt-collage';out.mkdir(exist_ok=True);cases=[]
buyer=Actor('http_buyer');operator=Actor(f['operator_login']);(private/'external-attempts.log').write_text('')
colors=[bytes([210,30,40,255]),bytes([30,170,60,255]),bytes([40,80,210,255]),bytes([220,170,30,255])]
def colorpng(color):
 def chunk(k,b):return struct.pack('>I',len(b))+k+b+struct.pack('>I',zlib.crc32(k+b)&0xffffffff)
 return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',256,256,8,6,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+color*256)*256))+chunk(b'IEND',b'')
head=None;hashes=[]
for i,c in enumerate(colors):
 data=colorpng(c);(out/f'source-{i+1}.png').write_bytes(data);u={'request_key':'collage-'+str(i),'expected_head':head,'mode':'PHOTO','image_base64':base64.b64encode(data).decode(),'image_field':'photo' if not i else 'photo_'+str(i),'fields':{},'selection':{'x':0,'y':0,'width':256,'height':256},'rights_confirmed':True,'processing_consent':True};v=buyer.api('upload_slot_'+str(i+1),'upload',u);head=v['sha256'];hashes.append(v['images'][u['image_field']]['cutout']['sha256'])
 if i==0:buyer.api('missing_slots_submit_denied','fields',{'expected_head':head,'submit_for_review':True,'expected_selection_revision':0},deny=True)
oldhead=head;order=['photo_3','photo','photo_1','photo_2'];v=buyer.api('reorder_all_slots','fields',{'expected_head':head,'image_order':order});head=v['sha256'];assert head!=oldhead
view=buyer.api('inspect_reordered_slots','view');assert [view['images'][k]['cutout_sha256'] for k in ['photo','photo_1','photo_2','photo_3']]==[hashes[3],hashes[0],hashes[1],hashes[2]]
buyer.api('pre_reorder_head_denied','fields',{'expected_head':oldhead,'submit_for_review':True,'expected_selection_revision':0},deny=True)
s=buyer.api('submit_current_collage','fields',{'expected_head':head,'submit_for_review':True,'expected_selection_revision':0});sel=s['sha256'];p={'operation':'PHOTO_INPUT_REVIEW','shop_key':'personalized_pod','selection_sha256':sel,'expected_revision':1,'decision':'APPROVED','note':'Synthetic independent four-photo current-order review'};operator.api('review_four_inputs','intake',p)
p={'operation':'PHOTO_LAYOUT','shop_key':'personalized_pod','selection_sha256':sel,'expected_revision':1,'template_key':f['input']['template_key'],'template_version':1,'note':'Synthetic four-photo layout review'};operator.api('review_collage_layout','publish',p);p.pop('note');p['operation']='PHOTO_PREPARE';render=operator.api('prepare_collage','publish',p)
inspection=operator.api('inspect_unreviewed_collage','intake',{'operation':'PHOTO_VIEW','shop_key':'personalized_pod'});assert len(inspection['photos'])==4;artifact=inspection['render_preview'];data=base64.b64decode(artifact['content_base64']);assert hashlib.sha256(data).hexdigest()==artifact['sha256'];(out/'operator-collage.png').write_bytes(data)
operator.api('approve_exact_collage_render','publish',{'operation':'PHOTO_APPROVE_RENDER','shop_key':'personalized_pod','selection_sha256':sel,'expected_revision':1,'note':'Synthetic four-panel artifact inspected independently'})
view=buyer.api('buyer_collage_preview','view');preview=view['product_preview'];assert preview['state']=='AVAILABLE';assert preview['sha256']==artifact['sha256'];assert base64.b64decode(preview['content_base64'])==data;(out/'buyer-collage.png').write_bytes(data)
body={'version_sha256':head,'context_sha256':preview['context_sha256']};approval=buyer.api('buyer_collage_preference','approve',body)
u['expected_head']=head;u['request_key']='collage-replacement';u['image_base64']=base64.b64encode(colorpng(bytes([160,40,180,255]))).decode();v2=buyer.api('replace_fourth_slot','upload',u);assert v2['sha256']!=head
buyer.api('old_collage_preference_denied','approve',body,deny=True);p['operation']='PHOTO_PREPARE';operator.api('old_collage_prepare_denied','publish',p,deny=True);view=buyer.api('replacement_collage_blocked','view');assert view['product_preview']['state']=='BLOCKED'
hosts=(private/'external-attempts.log').read_text().splitlines();assert all(h in ['127.0.0.1','localhost','::1'] for h in hosts)
summary={'case':'PHOTO_COLLAGE4 actual HTTP','cases':cases,'slot_source_sha256':hashes,'reorder':['photo_3','photo','photo_1','photo_2'],'artifact_sha256':artifact['sha256'],'preview_bytes_equal':True,'holds':{k:approval.get(k) for k in ['artwork_certified','supplier_certified','external_execution_authorized']},'safety':json.loads((ROOT/'receipt/safety-settings.json').read_text()),'nonloopback_http_attempts':0,'blocked_loopback_probes':len(hosts),'source_head':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'runtime_source_sha256':hashlib.sha256(Path('includes/POD/CustomerPhotoSelection.php').read_bytes()).hexdigest()};(out/'receipt.json').write_text(json.dumps(summary,indent=2));print(json.dumps({'passed':len(cases),'artifact_sha256':artifact['sha256']}))
