const fs = require('fs');
const views = ['dashboard','businesses','approvals','attention','research','products','digital','production','pod_personalized','pod_future_nonpersonalized','listings','orders','fulfillment','ai_budget','automation','integrations','audit','system','settings'];
const shops = ['digital','personalized_pod','standard_pod','jewelry'];
function fixtures(directory, scope = 'comprehensive') {
  if (!directory || !fs.statSync(directory).isDirectory()) throw new Error('Supply a rendered fixture directory.');
  if (!['comprehensive', 'representative'].includes(scope)) throw new Error('Unknown browser acceptance scope.');
  const expected = scope === 'comprehensive' ? views.flatMap(view => shops.map(shop => `portal-${view}--${shop}.html`)) : ['ai_budget','research','businesses','system'].map(view => `portal-${view}.html`);
  const actual = fs.readdirSync(directory).filter(file => /^portal-[a-z_]+(?:--[a-z_]+)?\.html$/.test(file)).sort();
  if (actual.length !== expected.length || expected.some(file => !actual.includes(file))) throw new Error(`Incomplete ${scope} fixture coverage: expected ${expected.length}, found ${actual.length}.`);
  for (const file of actual) if (!fs.statSync(`${directory}/${file}`).isFile() || !fs.statSync(`${directory}/${file}`).size) throw new Error(`Empty or invalid fixture: ${file}`);
  return actual;
}
module.exports = { fixtures, views, shops };
