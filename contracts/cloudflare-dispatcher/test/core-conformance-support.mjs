import { spawnSync } from 'node:child_process';
import { checkpointFixture } from './native-checkpoint-support.mjs';
import { SyntheticNativeCheckpointObserver } from '../native-checkpoint.mjs';
import { canonical, hash } from '../protocol.mjs';

export const CORE='78e7f973455a3c89319be06f0907caf2103658a7';
export const SPEC='6d19ac1c9971d5dd730fa910d08b34ad868e7d0f351b5996029dd60322ed3434';
export function coreProbe(input) {
  const r=spawnSync(process.env.DIGIFORGE_TEST_PHP||'php',[new URL('./core-conformance-probe.php',import.meta.url).pathname],{input:JSON.stringify(input),encoding:'utf8',timeout:10000,maxBuffer:1048576});
  if(r.error||r.status!==0)throw Error('Fixed local core conformance probe failed');
  return JSON.parse(r.stdout);
}
export async function conformanceFixture() {
  const f=await checkpointFixture(),observer=new SyntheticNativeCheckpointObserver(f.options());
  const observation=await observer.observe(f.request()),binding=structuredClone(f.context.binding);
  const inspection={schema:'DF_NATIVE_READ_ONLY_INSPECTION_1',version:'1.0.0',kind:'READINESS',scope_fingerprint:binding.scope_fingerprint,actor_id:1,access_receipt_hash:'b'.repeat(64),observation:{available_local_reads:['READINESS','HELD_OPERATION','ORDER_OBSERVATIONS'],epoch_dependent_operations:'DENIED',authority_evidence:'NOT_QUALIFIED',observation_scope:'LOCAL_CORE_ONLY'},read_only:true,mutation_authorized:false,independent_checkpoint_verified:false,recovery_epoch:null,checkpoint_epoch:null,network_request_permitted:false,external_execution_authorized:false,retry_permitted:false,release_hold:true};
  inspection.evidence_hash=await hash(canonical(inspection));
  const manifest={schema:'DF_NATIVE_CORE_MANIFEST_1',contract_version:'1.0.0',spec_sha256:SPEC,core_source_sha:CORE,authority:'DIGIFORGE_CORE',protocols:{base:'1.0.0',inspection:'1.0.0',publication:'1.0.0',workflow:'1.0.0',operations:'1.0.0',native:'1.0.0'},capabilities:['PANEL','GET_READ'],external_execution_authorized:false};
  // This literal sample is for schema tests; genuine native records are exercised separately in WordPress.
  return {f,input:{mode:'compare',manifest,inspection,binding,observation:structuredClone(observation),observer_binding:structuredClone(binding),now:f.clock.now,generation:observation.generation}};
}
