// Disposable fixture driver. All sockets and token material belong to createProcessFixture.
import { createInterface } from 'node:readline';
import { createPublicKey } from 'node:crypto';
import { createProcessFixture, offlineReleasedHolds } from './process-fixtures.mjs';
import { canonical } from '../protocol.mjs';

const lines = createInterface({ input: process.stdin });
const output = value => process.stdout.write(JSON.stringify(value) + '\n');
let fixture;
let intent;
let retained;
let raw;
let readBody;
try {
  for await (const line of lines) {
    const message=JSON.parse(line);
    if (message.action==='setup' && !fixture) {
      fixture=await createProcessFixture({ after(){} },{holds:offlineReleasedHolds,providerBehavior:'disconnect',coreScope:message.scope});
      intent=fixture.admissionBody();
      retained=fixture.state();
      output({intent});
    } else if (message.action==='reserve' && fixture && !raw) {
      const {request_id,challenge}=message.correlation;
      const reserved=await fixture.client().call('reserveOperation',intent,{request_id,challenge});
      if (reserved.disposition!=='ADMITTED') throw new Error('reservation_denied');
      readBody={
        operation_id:intent.operation_id,payload_fingerprint:intent.payload_fingerprint,
        original_request_id:request_id,original_challenge:challenge,expected_epoch:intent.expected_epoch,
        expected_generation:intent.expected_generation,retained_witness_head:retained.witness_head,
        retained_witness_sequence:retained.witness_sequence,
      };
      raw=canonical(await fixture.client().readOperationEnvelope(readBody));
      const { authority_id,installation_id,workload_id,deployment_digest,witness_id,root_set_revision }=fixture.identity;
      const key=role=>createPublicKey(fixture.publicMaps[role][role]).export({format:'jwk'}).x;
      output({raw,pins:{authority_id,installation_id,workload_id,deployment_digest,witness_id,root_set_revision,
        issuer_id:'issuer',witness_key_id:'witness',issuer_key:key('issuer'),witness_key:key('witness'),
        retained_head:retained.witness_head,retained_sequence:retained.witness_sequence}});
    } else if (message.action==='send' && fixture && raw) {
      const r=message.receipt;
      const data={operation_id:intent.operation_id,payload_fingerprint:intent.payload_fingerprint,
        allocation_id:r.allocation_id,allocation_revision:r.allocation_revision,checkpoint_epoch:r.epoch,
        generation:r.generation,admission_sequence:r.witness_sequence,admission_head:r.witness_head,
        core_journal_head:message.core_head,receipt_digest:r.receipt_digest};
      if(message.reject===true){
        const result=await fixture.control('SEND_RESERVED',{...data,admission_head:'0'.repeat(64)},{role:'issuer'});
        output({statuses:[result.response.status],state:fixture.operation().state,requests:fixture.requests.length});
        continue;
      }
      const responses=await Promise.all([
        fixture.control('SEND_RESERVED',data,{role:'issuer'}),
        fixture.control('SEND_RESERVED',data,{role:'issuer'}),
      ]);
      output({statuses:responses.map(r=>r.response.status).sort(),state:fixture.operation().state,
        requests:fixture.requests.length});
    } else if (message.action==='read' && fixture && readBody) {
      output({raw:canonical(await fixture.client().readOperationEnvelope(readBody))});
    } else if (message.action==='stop' && fixture) {
      const stop=await fixture.client('emergency').call('requestEmergency',{
        emergency_authority_reference:'emergency',emergency_event_id:'fixture-stop',scope_of_revocation:'installation',
        expected_generation:fixture.state().generation,
      });
      output({disposition:stop.disposition,requests:fixture.requests.length});
    } else throw new Error('fixture_protocol');
  }
} catch (error) {
  console.error(error.message);
  process.exitCode=1;
} finally { await fixture?.dispose(); }
