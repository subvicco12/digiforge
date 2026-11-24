// Disposable single-process test adapter. Never included in Worker deployment.
import { execFile } from 'node:child_process';
import { readFileSync, writeFileSync, renameSync, existsSync } from 'node:fs';
import { ProtectedReadAdmission } from './worker.mjs';

const [php,script,plugin,journal] = process.argv.slice(2);
const input = JSON.parse(readFileSync(0,'utf8'));
const invoke = (port,data) => new Promise((resolve,reject) => {
    const child = execFile(php,[script,plugin,journal,port],{
        encoding:'utf8',timeout:4000,maxBuffer:1048576,
    },(error,stdout,stderr) => {
        if (error) {
            reject(new Error('Fixture port failed: ' + port + ' ' + error.code + ' ' + stderr));
            return;
        }
        try { resolve(JSON.parse(stdout)); } catch (failure) { reject(failure); }
    });
    child.stdin.on('error',reject);
    child.stdin.end(JSON.stringify(data));
});
const proof = await invoke('verify',input);
const scope = proof.scope_digest;
const consumed = JSON.parse(readFileSync(journal,'utf8'));
const anchors = journal+'.anchor';
const sequence = existsSync(anchors) ? JSON.parse(readFileSync(anchors,'utf8')) : 0;
let floor = sequence;
const state = {epoch:'fixture',sequence:consumed.length,revision:1,scope_digest:scope,count:consumed.length,consumed};
const write = (path,value) => {
    writeFileSync(path+'.next',JSON.stringify(value),{mode:0o600});
    renameSync(path+'.next',path);
};
const storage = {transaction: async fn => fn({
    get: async () => state,
    put: async (_key,value) => write(journal,value.consumed),
})};
const service = fn => ({fetch:async request => fn(new URL(request.url).pathname,await request.json())});
const POLICY = service((path,request) => {
    if (request.scope_digest !== scope) {
        return Response.json({accepted:false});
    }
    if (path === '/snapshot') {
        return Response.json({epoch:'fixture',sequence:floor,revision:1,stop_all:false,
            external_lock:false,release_hold:false,revoked:[]});
    }
    if (path === '/admit') {
        if (request.prior_sequence !== floor || request.sequence !== floor+1) {
            return Response.json({accepted:false});
        }
        floor = request.sequence;
        write(anchors,floor);
    }
    return Response.json({...request,accepted:request.sequence===floor});
});
const CONTRACTS = service(async (path,data) => Response.json(await invoke(path.slice(1),data)));
const REPLY_SIGNER = service(async (_path,data) => Response.json(await invoke('sign',data)));
const FIXTURE_CUSTODY = service(async (_path,data) => {
    if (data.method !== 'GET' || data.redirect !== 'deny'
        || data.url !== 'https://api.printify.com'+input.request.path
        || data.scope.account !== 'fixture-account' || data.scope.shop !== '107'
        || data.scope.integration !== 'fixture-read' || data.scope.custody !== 'custody-ref:fixture') {
        return Response.json({}, {status:403});
    }
    const fixture = await invoke('get',{request:input.request});
    return Response.json(fixture.body,{status:fixture.status});
});

const gateway = new ProtectedReadAdmission({storage},{
    EXECUTION_MODE:'offline-fixture', POLICY, CONTRACTS, REPLY_SIGNER, FIXTURE_CUSTODY,
});
const result = await gateway.fetch(new Request('https://gateway.invalid/read',{
    method:'POST',body:JSON.stringify(input),
}));
if (!result.ok) {
    process.stderr.write('Workers fixture returned '+result.status+' '+await result.text()+'\n');
    process.exit(1);
}
process.stdout.write(await result.text());
