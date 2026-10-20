/* Read-only metadata view. Never grants execution or certifies referenced evidence. */
(() => {
  'use strict';
  document.querySelectorAll('[data-acceptance-status]').forEach(panel => {
    const parent = panel.closest('[data-reviewed-contracts]');
    const form = panel.querySelector('form');
    const output = panel.querySelector('[role="status"]');
    let generation = 0;
    form.addEventListener('input', () => { generation++; output.textContent = ''; output.setAttribute('aria-busy', 'false'); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const request = ++generation;
      output.textContent = 'Loading read-only status…';
      output.setAttribute('aria-busy', 'true');
      try {
        const url = new URL(parent.dataset.endpoint, location.href);
        if (url.origin !== location.origin) throw new Error('origin');
        if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route') + 'acceptance');
        else url.pathname += 'acceptance';
        const values = new FormData(form);
        url.searchParams.set('criterion', values.get('criterion'));
        url.searchParams.set('scope', values.get('scope'));
        const environment = values.get('environment');
        if (environment !== null) url.searchParams.set('environment', environment);
        const response = await fetch(url.href, {method: 'GET', cache: 'no-store', credentials: 'same-origin', headers: {'X-WP-Nonce': parent.dataset.nonce}});
        if (!response.ok) throw new Error('unavailable');
        const state = await response.json();
        if (request !== generation) return;
        if (state.criterion !== values.get('criterion') || state.scope !== values.get('scope')) throw new Error('scope');
        const evaluated = state.policy_evaluation;
        if (evaluated && evaluated.state !== 'UNKNOWN' && (evaluated.shop_key !== state.shop_key || evaluated.criterion !== state.criterion || evaluated.scope !== state.scope || evaluated.environment !== environment)) throw new Error('policy scope');
        const policyEvaluation = evaluated ? {state: evaluated.state, value_state: evaluated.value_state, metrics: evaluated.metrics, source_records: evaluated.source_records, subject: evaluated.subject, period: evaluated.period, recommendation: evaluated.recommendation, reason: evaluated.reason, feedback: evaluated.feedback, evidence_state: 'UNVERIFIED', purpose_verified: false, external_execution_authorized: false, supplier_approved: false, linguistic_approved: false, production_authorized: false, publishing_allowed: false, ai_execution_allowed: false} : {state: 'UNKNOWN', evidence_state: 'UNVERIFIED'};
        const resolved = state.evidence_resolution;
        if (resolved && (resolved.shop_key !== state.shop_key || resolved.criterion !== state.criterion || resolved.scope !== state.scope || (environment !== null && resolved.environment !== environment))) throw new Error('resolution scope');
        if (resolved && (!['UNKNOWN', 'MATCHED_LOCAL_RECORDS'].includes(resolved.resolution_state) || !Array.isArray(resolved.references) || resolved.references.length > 64 || !Number.isInteger(resolved.matched_record_count) || resolved.matched_record_count < 0 || resolved.matched_record_count > resolved.references.length || (resolved.resolution_state === 'MATCHED_LOCAL_RECORDS' && (resolved.references.length === 0 || resolved.matched_record_count !== resolved.references.length)))) throw new Error('resolution shape');
        const references = resolved ? resolved.references.map(ref => ({reference: ref.reference, state: ref.state, reason: ref.reason, record_type: ref.record_type, sha256: ref.sha256, record_version: ref.record_version, review_revision: ref.review_revision, effective_at: ref.effective_at, expires_at: ref.expires_at, occurrences: ref.occurrences, purpose_verified: false})) : [];
        const resolution = resolved ? {environment: resolved.environment, resolution_state: resolved.resolution_state, matched_record_count: resolved.matched_record_count, references, purpose_verified: false, evidence_state: 'UNVERIFIED', value_state: 'UNKNOWN', supplier_approved: false, publishing_allowed: false, ai_execution_allowed: false, external_execution_authorized: false} : {resolution_state: 'UNKNOWN', evidence_state: 'UNVERIFIED', purpose_verified: false};
        output.textContent = JSON.stringify({criterion: state.criterion, scope: state.scope, status: state.status, review_status: state.review_status, review_revision: state.review_revision, effective_at: state.effective_at, expires_at: state.expires_at, revoked: state.revoked, evidence_references: state.evidence_references, policy_evaluation: policyEvaluation, evidence_resolution: resolution, value_state: 'UNKNOWN', evidence_state: 'UNVERIFIED', external_execution_authorized: false}, null, 2);
      } catch (_) {
        if (request === generation) output.textContent = 'UNAVAILABLE — no current review or certification can be inferred.';
      } finally {
        if (request === generation) output.setAttribute('aria-busy', 'false');
      }
    });
  });
})();
