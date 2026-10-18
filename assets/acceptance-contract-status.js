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
        const response = await fetch(url.href, {method: 'GET', credentials: 'same-origin', headers: {'X-WP-Nonce': parent.dataset.nonce}});
        if (!response.ok) throw new Error('unavailable');
        const state = await response.json();
        if (request !== generation) return;
        output.textContent = JSON.stringify({criterion: state.criterion, scope: state.scope, status: state.status, review_status: state.review_status, review_revision: state.review_revision, effective_at: state.effective_at, expires_at: state.expires_at, revoked: state.revoked, evidence_references: state.evidence_references, value_state: 'UNKNOWN', evidence_state: 'UNVERIFIED', external_execution_authorized: false}, null, 2);
      } catch (_) {
        if (request === generation) output.textContent = 'UNAVAILABLE — no current review or certification can be inferred.';
      } finally {
        if (request === generation) output.setAttribute('aria-busy', 'false');
      }
    });
  });
})();
