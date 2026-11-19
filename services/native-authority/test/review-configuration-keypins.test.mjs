import test from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync } from 'node:crypto';
import { loadConfig, initializeStore, serviceBuildDigest } from '../config.mjs';
import { AuthorityError } from '../errors.mjs';
import { witnessFixture } from './server-fixtures.mjs';

test('unchanged dual provisioning cannot approve a replacement witness signer for the existing sealed authority', t => {
  const f = witnessFixture(t), original = JSON.stringify(f.config.provisioning);
  const accepted = initializeStore(loadConfig(f.path, 'witness')), before = accepted.state(); accepted.close();
  const replacement = generateKeyPairSync('ed25519');
  f.config.keys.witness = { witness_replacement: replacement.publicKey.export({ type: 'spki', format: 'pem' }) };
  f.config.keys.signing_key_id = 'witness_replacement';
  f.config.keys.signing_private_key_path = f.write('replacement-witness.pem', replacement.privateKey.export({ type: 'pkcs8', format: 'pem' }));
  // Deliberately do not use fixture.save(): the original real owner/recovery signatures remain unchanged.
  const path = f.write('replacement-config.json', f.config);
  assert.equal(JSON.stringify(f.config.provisioning), original); assert.equal(serviceBuildDigest(), f.identity.deployment_digest);
  let failure, observed;
  try {
    const config = loadConfig(path, 'witness'), reopened = initializeStore(config);
    try { const state = reopened.state(); observed = { key_id: config.keyId, epoch_unchanged: state.epoch === before.epoch, root_revision: state.root_set_revision, generation: state.generation }; }
    finally { reopened.close(); }
  } catch (error) { failure = error; }
  assert.ok(failure instanceof AuthorityError, 'A replacement signer needs independent provisioning approval: ' + JSON.stringify(observed));
});
