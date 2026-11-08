// Native Node VM models two browser documents sharing tab-scoped storage.
const assert=require('node:assert/strict');const vm=require('node:vm');const fs=require('node:fs');
const script=fs.readFileSync('assets/portal-access.js','utf8');const memory=new Map();const token='a'.repeat(64);
const storage={setItem:(k,v)=>memory.set(k,v),getItem:k=>memory.get(k)||null,removeItem:k=>memory.delete(k)};
function page(hash,field){const history={replaceState(...args){history.destination=args[2];}};vm.runInNewContext(script,{document:{getElementById:()=>field},window:{location:{hash,pathname:'/',search:'?df_view=invitation'}},history,sessionStorage:storage});return history;}
const h=page('#invite='+token,null);assert.equal(storage.getItem('digiforge.pendingInvitation'),token,'login document must retain invitation fragment');assert.equal(h.destination,'/?df_view=invitation');
const input={value:''};page('',input);assert.equal(input.value,token,'acceptance document must recover the private credential');assert.equal(memory.size,0,'credential removed after populating form');
page('#invite=not-a-token',null);assert.equal(memory.size,0);console.log('PASS: guest login/acceptance fragment handoff, removal, malformed input; no query transport');
