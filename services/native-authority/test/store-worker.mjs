import { AuthorityStore } from '../store.mjs';
const [path,raw,time,action]=process.argv.slice(2);const store=new AuthorityStore(path);
try{
 const request=JSON.parse(raw);let op;
 if(action==='race-send'){op=store.markSending(request.method_body.operation_id,request.method_body.expected_generation,Number(time));}
 else if(action==='race-abandon'){const emergency=store.emergency('process-abandon',request.method_body.expected_generation,Number(time));op=store.operation(request.method_body.operation_id);op=store.abandonUnsent(op.operation_id,op.allocation_id,op.allocation_revision,op.generation,emergency.witness_head,Number(time));}
 else op=store.admit(request,Number(time));
 if(action==='crash-sending'){store.markSending(op.operation_id,op.generation,Number(time));process.stdout.write(JSON.stringify({ok:true,operation_id:op.operation_id})+'\n');process.exit(23);}
 process.stdout.write(JSON.stringify({ok:true,operation_id:op.operation_id,state:op.state})+'\n');store.close();
}catch(e){process.stdout.write(JSON.stringify({ok:false,code:e.code??'worker_error'})+'\n');store.close();}
