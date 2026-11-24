/* Customer drafts and preferences only. No production actions or external providers. */
(() => {
 'use strict';
 document.querySelectorAll('[data-df-editor]').forEach(root => {
  const q = s => root.querySelector(s), status = q('[data-status]');
  let state = null, dirty = false, busy = false, generation = 0, photo = null, start = null, box = null, photoGeneration = 0;
  const message = s => { status.textContent = s; };
  const invalidate = () => { dirty = true; q('[data-approve]').disabled = true; q('[data-product]').hidden = true; q('[data-product-status]').textContent = 'Changes are unsaved. Save and refresh before approval.'; };
  const request = async (action, body) => {
   const response = await fetch(root.dataset.endpoint + action, {method:body === undefined ? 'GET':'POST', credentials:'same-origin', cache:'no-store', headers:{'X-WP-Nonce':root.dataset.nonce,'Content-Type':'application/json'}, ...(body === undefined ? {} : {body:JSON.stringify(body)})});
   const result = await response.json(); if (!response.ok) throw new Error(result.message || 'The request failed. Refresh and try again.'); return result;
  };
  const image = (selector, bytes, mime) => { const el=q(selector); el.hidden=!bytes; if(bytes) el.src=`data:${mime};base64,${bytes}`; else el.removeAttribute('src'); };
  const fields = () => {
   const values={}; q('[data-fields]').querySelectorAll('[data-field]').forEach(el=>{ if(el.value !== '') values[el.dataset.field]=el.type==='number'?Number(el.value):el.value; }); return values;
  };
  const controls = () => { q('[data-form]').querySelectorAll('input,select').forEach(el=>{el.disabled=busy;}); q('[data-upload]').disabled=busy || !state; q('[data-save-fields]').disabled=busy || !state?.version_sha256; q('[data-refresh]').disabled=busy; q('[data-approve]').disabled=busy || dirty || state?.product_preview.state!=='AVAILABLE' || state?.product_preview.customer_approved===true; };
  const paintImages = () => { const im=state?.images[q('[data-slot]').value];image('[data-original]',im?.original_base64,im?.original_mime);image('[data-cutout]',im?.cutout_base64,'image/png');q('[data-quality]').textContent=im?.failure?'Extraction did not produce a usable cutout. Replace with a transparent image or request correction.':im?'Inspect hair, ears, whiskers, halos and clipping. This draft is not certified.':''; };
  const render = data => {
   state=data; dirty=false; q('[data-fields]').replaceChildren(); const slot=q('[data-slot]'), selected=slot.value;slot.replaceChildren();
   data.schema.forEach(f=>{ if(f.type==='image_reference'){const o=document.createElement('option');o.value=f.key;o.textContent=f.key;slot.append(o);return;}const label=document.createElement('label');label.append(document.createTextNode(f.key+(f.required?' (required)':'')));const el=document.createElement(f.type==='choice'?'select':'input');el.dataset.field=f.key;if(f.type==='choice'){const empty=document.createElement('option');empty.value='';empty.textContent='Choose';el.append(empty);(f.choices||f.options).forEach(c=>{const o=document.createElement('option');o.value=c;o.textContent=c;el.append(o);});}else{el.type=['number','date'].includes(f.type)?f.type:'text';if(f.type==='number')el.step='any';if(f.max_length)el.maxLength=f.max_length;if(f.minimum!==undefined)el.min=f.minimum;if(f.maximum!==undefined)el.max=f.maximum;}el.value=data.fields[f.key]??'';el.addEventListener('input',invalidate);label.append(el);q('[data-fields]').append(label);});
   if([...slot.options].some(o=>o.value===selected))slot.value=selected;paintImages();
   const p=data.product_preview;image('[data-product]',p.state==='AVAILABLE'?p.content_base64:null,'image/png');q('[data-product-status]').textContent=p.state==='AVAILABLE'?`${p.label}. Variant ${p.variant_key}${p.color_label?' — '+p.color_label:''}${p.product_family?' / '+p.product_family:''}. ${p.customer_approved?'Your digital preference is recorded.':'Review before approving.'}`:p.message;
   q('[data-history]').replaceChildren();data.history.forEach(h=>{const li=document.createElement('li');li.textContent=`Version ${h.revision} — ${h.created_at}`;q('[data-history]').append(li);});controls();message(`Order ${data.order_id}. ${data.version_sha256?'Current artwork loaded.':'Upload your artwork to begin.'}`);
  };
  const run = async operation => { if(busy)return;busy=true;controls();const n=++generation;try{await operation(n);}catch(e){message(e.message);}finally{busy=false;controls();} };
  const refresh = async n => { const data=await request('view');if(n===generation)render(data); };
  q('[data-refresh]').addEventListener('click',()=>run(refresh));
  q('[data-slot]').addEventListener('change',paintImages);
  q('[data-background]').addEventListener('change',e=>q('[data-cutout-background]').className='df-image '+e.target.value);
  q('[data-zoom]').addEventListener('input',e=>{q('[data-original]').style.width=e.target.value+'%';q('[data-cutout]').style.width=e.target.value+'%';});
  root.querySelectorAll('[name=method]').forEach(el=>el.addEventListener('change',()=>{q('[data-selection]').hidden=el.value!=='AUTO';invalidate();}));
  q('[data-file]').addEventListener('change',async()=>{invalidate();if(photo)photo.bitmap.close();photo=null;box=null;const loading=++photoGeneration;const file=q('[data-file]').files[0];if(!file)return;try{if(file.size>4500000||!['image/png','image/jpeg'].includes(file.type))throw new Error('Use a PNG or JPEG no larger than 4.5 MB.');const bitmap=await createImageBitmap(file,{imageOrientation:'from-image'});if(bitmap.width*bitmap.height>1048576){bitmap.close();throw new Error('Resize the image to at most 1,048,576 pixels before upload.');}if(loading!==photoGeneration){bitmap.close();return;}photo={file,bitmap};const c=q('[data-source]');c.width=bitmap.width;c.height=bitmap.height;c.getContext('2d').drawImage(bitmap,0,0);message('Image ready. Automatic mode requires an interior head rectangle and foreground point.');}catch(e){message(e.message);}});
  const coordinates=()=>Object.fromEntries([...root.querySelectorAll('[data-coordinate]')].map(e=>[e.dataset.coordinate,Number(e.value)]));
  const setCoordinates = values => root.querySelectorAll('[data-coordinate]').forEach(e=>{if(values[e.dataset.coordinate]!==undefined)e.value=values[e.dataset.coordinate];});
  const canvas=q('[data-source]');const point=e=>{const r=canvas.getBoundingClientRect();return {x:Math.round((e.clientX-r.left)*canvas.width/r.width),y:Math.round((e.clientY-r.top)*canvas.height/r.height)};};
  canvas.addEventListener('pointerdown',e=>{if(!photo)return;start=point(e);canvas.setPointerCapture(e.pointerId);});canvas.addEventListener('pointerup',e=>{if(!photo||!start)return;const p=point(e);if(Math.abs(p.x-start.x)>5&&Math.abs(p.y-start.y)>5){box={x:Math.min(p.x,start.x),y:Math.min(p.y,start.y),width:Math.abs(p.x-start.x),height:Math.abs(p.y-start.y)};setCoordinates(box);}else setCoordinates({px:p.x,py:p.y});start=null;canvas.getContext('2d').drawImage(photo.bitmap,0,0);if(box){const ctx=canvas.getContext('2d');ctx.strokeStyle='#ff4f00';ctx.lineWidth=2;ctx.strokeRect(box.x,box.y,box.width,box.height);}invalidate();});
  root.querySelectorAll('[data-coordinate]').forEach(el=>el.addEventListener('input',invalidate));
  q('[data-form]').addEventListener('submit',e=>{e.preventDefault();run(async n=>{if(!photo||!q('[data-rights]').checked||!q('[data-consent]').checked)throw new Error('Choose an image and confirm permission and processing consent.');const mode=q('[name=method]:checked').value;const c=coordinates();const selection=mode==='AUTO'?{subjects:[{x:c.x,y:c.y,width:c.width,height:c.height}],selected:0,foreground_points:[{x:c.px,y:c.py}],trimap_width:8}:null;const bytes=new Uint8Array(await photo.file.arrayBuffer());let binary='';for(let i=0;i<bytes.length;i+=8192)binary+=String.fromCharCode(...bytes.subarray(i,i+8192));await request('upload',{request_key:crypto.randomUUID(),expected_head:state.version_sha256,mode,image_base64:btoa(binary),image_field:q('[data-slot]').value,fields:fields(),selection,rights_confirmed:true,processing_consent:true});q('[data-file]').value='';photo.bitmap.close();photo=null;await refresh(n);});});
  q('[data-save-fields]').addEventListener('click',()=>run(async n=>{await request('fields',{expected_head:state.version_sha256,fields:fields()});await refresh(n);}));
  q('[data-approve]').addEventListener('click',()=>run(async n=>{if(dirty||state.product_preview.state!=='AVAILABLE')throw new Error('Refresh the approved preview first.');await request('approve',{version_sha256:state.version_sha256,context_sha256:state.product_preview.context_sha256});await refresh(n);}));
  run(refresh);
 });
})();
