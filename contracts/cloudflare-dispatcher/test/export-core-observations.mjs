// Fixed synthetic observation export to stdout only. No credentials, HTTP or deployment.
import { conformanceFixture,CORE } from './core-conformance-support.mjs';
const {input}=await conformanceFixture();
process.stdout.write(JSON.stringify({schema:'DF_SYNTHETIC_CORE_OBSERVATIONS_1',core_sha:CORE,now:input.now,generation:input.generation,binding:input.binding,observation:input.observation}));
