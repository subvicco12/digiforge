/* Executable DOM-boundary tests; synthetic transport does not certify WordPress authority. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function scenario({foreign=false, failure=false, stale=false, decision=false, inspect=false, pretty=false, invalid=false}={}) {
  const events={}, output={textContent:'',setAttribute(){}}, calls=[];
  const values={payload:JSON.stringify(decision ? {criterion:'BP-quality-feedback',scope:'product:1',expected_revision:1,proposal_hash:'a'.repeat(64),decision:'APPROVED'} : {input:{version:2,criterion:'BP-quality-feedback',scope:'product:1'},effective_at:1,expires_at:2000000000,expected_revision:0}), confirm:'CONFIRM_LOCAL_ACCEPTANCE_REVIEW'};
  values.criterion='BP-quality-feedback';values.scope='product:1';
  if(invalid) values.confirm='';
  const form={dataset:{acceptanceWrite:inspect?'inspect':decision?'decision':'proposal'},addEventListener:(name,fn)=>events[name]=fn};
  const panel={dataset:{shop:'digital',endpoint:'https://example.org/?rest_route=/digiforge/v1/reviewed-contracts/digital/',nonce:'synthetic'},querySelectorAll:()=>[form],querySelector:()=>output};
  if(pretty) panel.dataset.endpoint='https://example.org/wp-json/digiforge/v1/reviewed-contracts/digital/';
  let release;
  const context={document:{querySelectorAll:()=>[panel]},location:{href:'https://example.org/',origin:'https://example.org'},URL,TextEncoder,crypto:{randomUUID:()=> 'synthetic-request-1234'},FormData:class {get(key){return values[key];}},fetch:async(url,options)=>{
    calls.push({url,options});
    if(stale) await new Promise(resolve=>release=resolve);
    return {ok:!failure,json:async()=>({shop_key:foreign?'personalized_pod':'digital',criterion:'BP-quality-feedback',scope:'product:1',status:'PENDING',revision:1,proposal_hash:'a'.repeat(64),payload:{safe:'<img onerror=bad>'},external_execution_authorized:false})};
  }};
  vm.runInNewContext(fs.readFileSync('assets/acceptance-review.js','utf8'),context);
  const pending=events.submit({preventDefault(){}});
  if(stale) {events.input();release();}
  await pending;
  if(invalid) {assert.equal(calls.length,0);assert.match(output.textContent,/UNAVAILABLE/);return;}
  assert.equal(calls.length,1);
  const request=calls[0];const url=new URL(request.url);
  assert.equal(pretty?url.pathname.replace('/wp-json',''):url.searchParams.get('rest_route'),'/digiforge/v1/reviewed-contracts/digital/acceptance'+(inspect?'/proposal':decision?'/decision':''));
  assert.equal(request.options.method,inspect?'GET':'POST');assert.equal(request.options.headers['X-WP-Nonce'],'synthetic');
  if(inspect) {assert.equal(request.options.body,undefined);assert.equal(url.searchParams.get('scope'),'product:1');}
  else {const sent=JSON.parse(request.options.body);assert.equal(sent.confirm,values.confirm);assert.equal(sent.request_id,'synthetic-request-1234');}
  if(stale) assert.equal(output.textContent,'');
  else if(foreign||failure) assert.match(output.textContent,/UNAVAILABLE/);
  else {assert.match(output.textContent,/PENDING/);assert.match(output.textContent,/<img onerror=bad>/);assert.match(output.textContent,/UNVERIFIED/);}
}
(async()=>{let checks=0;for(const pretty of [false,true])for(const options of [{},{decision:true},{inspect:true},{inspect:true,foreign:true},{foreign:true},{failure:true},{stale:true},{invalid:true}]){await scenario({...options,pretty});checks++;}console.log(`PASS: ${checks} acceptance review transport scenarios`);})().catch(error=>{console.error(error);process.exit(1);});
