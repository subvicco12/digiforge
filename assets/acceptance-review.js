/* Local acceptance proposals only. Server-side independent review remains mandatory. */
(() => {
  'use strict';
  document.querySelectorAll('[data-acceptance-review]').forEach(panel => {
    const output = panel.querySelector('[role="status"]');
    let generation = 0;
    panel.querySelectorAll('form').forEach(form => {
      form.addEventListener('input', () => { generation++; output.textContent = ''; output.setAttribute('aria-busy', 'false'); });
      form.addEventListener('submit', async event => {
        event.preventDefault();
        const attempt = ++generation;
        output.textContent = 'Recording local review…';
        output.setAttribute('aria-busy', 'true');
        try {
          const values = new FormData(form);
          const inspect = form.dataset.acceptanceWrite === 'inspect';
          const raw = values.get('payload');
          if (!inspect && (typeof raw !== 'string' || new TextEncoder().encode(raw).length > 30000 || values.get('confirm') !== 'CONFIRM_LOCAL_ACCEPTANCE_REVIEW')) throw new Error('input');
          const body = inspect ? {criterion: values.get('criterion'), scope: values.get('scope')} : JSON.parse(raw);
          if (!body || Array.isArray(body) || typeof body !== 'object') throw new Error('shape');
          const decision = form.dataset.acceptanceWrite === 'decision';
          const fields = inspect ? ['criterion', 'scope'] : decision ? ['criterion', 'scope', 'expected_revision', 'proposal_hash', 'decision'] : ['input', 'effective_at', 'expires_at', 'expected_revision'];
          if (Object.keys(body).length !== fields.length || fields.some(key => !Object.hasOwn(body, key))) throw new Error('fields');
          if (!inspect) { body.request_id = crypto.randomUUID(); body.confirm = values.get('confirm'); }
          const url = new URL(panel.dataset.endpoint, location.href);
          if (url.origin !== location.origin) throw new Error('origin');
          const suffix = 'acceptance' + (inspect ? '/proposal' : decision ? '/decision' : '');
          if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route') + suffix);
          else url.pathname += suffix;
          if (inspect) { url.searchParams.set('criterion',body.criterion); url.searchParams.set('scope',body.scope); }
          const options = {method: inspect ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': panel.dataset.nonce}};
          if (!inspect) options.body = JSON.stringify(body);
          const response = await fetch(url.href, options);
          if (!response.ok) throw new Error('unavailable');
          const state = await response.json();
          if (attempt !== generation) return;
          const identity = decision || inspect ? body : body.input;
          if (state.shop_key !== panel.dataset.shop || state.criterion !== identity.criterion || state.scope !== identity.scope || state.external_execution_authorized !== false) throw new Error('scope');
          output.textContent = JSON.stringify({...state, evidence_state: 'UNVERIFIED', production_authorized: false, supplier_approved: false, publishing_allowed: false}, null, 2);
        } catch (_) {
          if (attempt === generation) output.textContent = 'UNAVAILABLE — refresh exact current evidence before another explicit submission. No automatic retry.';
        } finally {
          if (attempt === generation) output.setAttribute('aria-busy', 'false');
        }
      });
    });
  });
})();
