/* Local-only reviewed contract editor. Server permissions, not the UI, enforce authority. */
(function () {
    'use strict';
    document.querySelectorAll('[data-reviewed-contracts]').forEach(function (panel) {
        var endpoint = panel.dataset.endpoint;
        var nonce = panel.dataset.nonce;
        function requestId() {
            if (!window.crypto || !window.crypto.randomUUID) { throw new Error('Secure request identifiers are unavailable.'); }
            return window.crypto.randomUUID();
        }
        function number(value) {
            var n = Number(value);
            if (!/^\d+$/.test(value) || !Number.isSafeInteger(n)) { throw new Error('Exact integer values are required.'); }
            return n;
        }
        function message(kind, text) {
            panel.querySelector('[data-contract-result="' + kind + '"]').textContent = text;
        }
        async function call(kind, path, body) {
            var url = new URL(endpoint, window.location.href), parts = path.split('?');
            var tail = kind + parts[0];
            if (url.searchParams.has('rest_route')) { url.searchParams.set('rest_route', url.searchParams.get('rest_route') + tail); }
            else { url.pathname += tail; }
            new URLSearchParams(parts[1] || '').forEach(function (value, key) { url.searchParams.set(key, value); });
            var response = await fetch(url.href, {
                method: body ? 'POST' : 'GET', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-WP-Nonce': nonce},
                body: body ? JSON.stringify(body) : undefined
            });
            var data = await response.json();
            if (!response.ok) { throw new Error(data.message || 'Local evidence unavailable; no decision was applied.'); }
            return data;
        }
        function show(kind, state, key) {
            var read = panel.querySelector('[data-contract-read="' + kind + '"]');
            key = state.policy_key || key;
            if (!key && kind === 'ownership') {
                // The server supplies its canonical key; the browser never derives ownership.
                key = state.binding_key;
            }
            if (key) { read.elements.key.value = key; }
            message(kind, JSON.stringify(state, null, 2));
            var decisions = panel.querySelector('[data-contract-decisions="' + kind + '"]');
            decisions.replaceChildren();
            if (!key) { return; }
            var choices = state.status === 'PENDING' ? ['APPROVED', 'REJECTED'] : state.status === 'APPROVED' ? ['REVOKED'] : [];
            choices.forEach(function (decision) {
                var button = document.createElement('button');
                button.type = 'button'; button.className = 'df-button'; button.textContent = decision + ' local version ' + state.version;
                button.addEventListener('click', async function () {
                    // Consume a button snapshot once; errors require a fresh server read.
                    decisions.replaceChildren();
                    try {
                        var result = await call(kind, '/decision', {key: key, expected_revision: state.revision, proposal_hash: state.proposal_hash, decision: decision, request_id: requestId()});
                        show(kind, result, key);
                    } catch (error) { message(kind, error.message + ' Reload current evidence before retrying.'); }
                });
                decisions.append(button);
            });
        }
        panel.querySelectorAll('[data-contract-kind]').forEach(function (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault(); var kind = form.dataset.contractKind;
                panel.querySelector('[data-contract-decisions="' + kind + '"]').replaceChildren();
                try {
                    var values = new FormData(form), input = {};
                    if (kind === 'policy') {
                        ['environment', 'scope', 'workflow', 'capability'].forEach(function (key) { input[key] = values.get(key); });
                        input.enabled = values.get('enabled') === 'true';
                    } else {
                        ['integration_id', 'provider_mapping_id', 'production_template_id'].forEach(function (key) { input[key] = number(values.get(key)); });
                        input.source_sha256 = values.get('source_sha256'); input.evidence_reference = values.get('evidence_reference');
                    }
                    var effective = Date.parse(values.get('effective_at')), expires = Date.parse(values.get('expires_at'));
                    if (!Number.isFinite(effective) || !Number.isFinite(expires)) { throw new Error('Explicit effective and expiry dates are required.'); }
                    var result = await call(kind, '', {input: input, effective_at: Math.floor(effective / 1000), expires_at: Math.floor(expires / 1000), expected_revision: number(values.get('expected_revision')), request_id: requestId()});
                    show(kind, result);
                } catch (error) { message(kind, error.message); }
            });
        });
        panel.querySelectorAll('[data-contract-read]').forEach(function (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault(); var kind = form.dataset.contractRead;
                panel.querySelector('[data-contract-decisions="' + kind + '"]').replaceChildren();
                try { show(kind, await call(kind, '?key=' + encodeURIComponent(form.elements.key.value)), form.elements.key.value); }
                catch (error) { message(kind, error.message); }
            });
        });
    });
}());
