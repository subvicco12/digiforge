const test = require('node:test'), assert = require('node:assert/strict'), fs = require('fs'), os = require('os'), path = require('path');
const { fixtures, views, shops } = require('./portal-fixture-contract.cjs');
test('empty, incomplete and unexpected coverage fails; exact comprehensive coverage passes', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'df-fixtures-'));
  try {
    assert.throws(() => fixtures(directory), /Incomplete/);
    fs.writeFileSync(path.join(directory, 'portal-system--digital.html'), '<div>fixture</div>');
    assert.throws(() => fixtures(directory), /Incomplete/);
    for (const view of views) for (const shop of shops) fs.writeFileSync(path.join(directory, `portal-${view}--${shop}.html`), '<div>fixture</div>');
    assert.equal(fixtures(directory).length, 76);
    fs.writeFileSync(path.join(directory, 'portal-system--digital.html'), '');
    assert.throws(() => fixtures(directory), /Empty/);
    fs.writeFileSync(path.join(directory, 'portal-system--digital.html'), '<div>fixture</div>');
    fs.writeFileSync(path.join(directory, 'portal-unknown--digital.html'), '<div>unexpected</div>');
    assert.throws(() => fixtures(directory), /Incomplete/);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});
test('representative scope is explicit and cannot claim comprehensive coverage', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'df-fixtures-'));
  try {
    for (const view of ['ai_budget','research','businesses','system']) fs.writeFileSync(path.join(directory, `portal-${view}.html`), '<div>fixture</div>');
    assert.equal(fixtures(directory, 'representative').length, 4);
    assert.throws(() => fixtures(directory), /Incomplete/);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});
