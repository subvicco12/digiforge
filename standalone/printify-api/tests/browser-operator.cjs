const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const origin = 'http://127.0.0.1:18276';
    const admin = origin + '/wp-admin/tools.php?page=printify-api-standalone';
    const browser = await chromium.launch({
        executablePath: '/usr/bin/chromium',
        args: ['--no-sandbox', '--disable-background-networking', '--disable-quic',
            '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1'],
    });
    try {
        const context = await browser.newContext({
            viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, serviceWorkers: 'block',
        });
        let outside = 0;
        await context.route('**/*', route => {
            if (new URL(route.request().url()).origin === origin) return route.continue();
            outside++;
            return route.abort();
        });
        const page = await context.newPage();
        page.setDefaultTimeout(8000);
        const externalResponses = [];
        page.on('response', response => {
            if (new URL(response.url()).origin !== origin) externalResponses.push(response.url());
        });
        await page.goto(admin);
        await page.locator('#user_login').fill('admin');
        await page.locator('#user_pass').fill('local-fixture-only-123');
        await page.locator('#wp-submit').click();
        const panel = page.getByRole('heading', { name: 'Protected Printify reads' }).locator('..');
        const main = panel.locator('form').filter({ has: page.locator('select[name=df_printify_get_operation]') });
        const screenshot = async (name, target) => {
            await target.scrollIntoViewIfNeeded();
            await page.screenshot({ path: path.join(process.argv[2], name + '.png') });
        };
        const awaitObservation = async () => {
            await panel.getByText('SYNTHETIC_DIRECT_OBSERVED', { exact: true }).waitFor({timeout: 4500}).catch(async error => {
                console.error(await panel.getByRole('status').allTextContents());
                throw error;
            });
            return JSON.parse(await panel.locator('pre').innerText());
        };
        const read = async (operation, fields = {}) => {
            await page.goto(admin);
            const selector = main.locator('select[name=df_printify_get_operation]');
            await selector.selectOption(operation);
            assert((await selector.boundingBox()).x + (await selector.boundingBox()).width <= 390);
            for (const [key, value] of Object.entries(fields)) {
                await main.locator('[name="df_printify_get[' + key + ']"]').fill(String(value));
            }
            await main.getByRole('button', { name: 'Refresh synthetic observation' }).click();
            return awaitObservation();
        };
        const follow = async (button, label, value) => {
            const control = panel.getByRole('button', { name: button, exact: true });
            if (label) await control.locator('..').getByRole('combobox', { name: label, exact: true }).selectOption(value);
            await control.click();
            return awaitObservation();
        };
        await page.getByRole("link", { name: "Account configuration" }).click();
        await page.getByRole("heading", { name: "Native readiness" }).waitFor();
        await panel.getByText(/Shop 107 · account fixture-account/).waitFor();
        await panel.getByText('Read archive ready. Mutation execution denied.').waitFor();
        await panel.getByText('Read availability and qualification').click();
        await panel.getByText(/Production mutation, credential custody, and independent recovery remain held/).waitFor();
        await screenshot('mobile-readiness', panel.getByText('Read availability and qualification'));
        const blueprints = await read('BLUEPRINTS_READ');
        assert(blueprints.some(row => row.id === 42));
        assert((await follow('Read selected blueprint providers', 'Blueprint', '42')).some(row => row.id === 7));
        const products = await read('PRODUCTS_READ');
        assert.equal(products.data[0].id, 'product-1');
        const product = await follow('Read product product-1');
        assert.equal(product.id, 'product-1');
        const gpsr = await follow('Read product safety information');
        assert.equal(gpsr.supplier_certified, null);
        await screenshot('mobile-gpsr', panel.getByText(/Fixture product safety information/).last());
        const orders = await read('ORDERS_READ');
        assert.equal(orders.data[0].id, 'order-1');
        const order = await follow('Read selected order', 'Order', 'order-1');
        assert.equal(order.shipments[0].tracking_reference, 'fixture-tracking-1');
        const supports = await follow('Read order support requests');
        assert.equal(supports.support_requests.length, 11);
        const support = await follow('Read selected support request', 'Support request', 'support-11');
        assert.equal(support.support_request.id, 'support-11');
        assert(!JSON.stringify(support).includes('fixture-private-description'));
        await page.goto(admin);
        assert.deepEqual(JSON.parse(await panel.locator('pre').innerText()), support);
        await screenshot('mobile-support', panel.locator('pre'));
        const post = async (form, overrides) => {
            const fields = await form.evaluate(element => [...new FormData(element).entries()]);
            await page.evaluate(({ fields, overrides }) => {
                const form = document.createElement('form');
                form.method = 'POST';
                const values = new URLSearchParams(fields);
                for (const [key, value] of Object.entries(overrides)) values.set(key, value);
                for (const [name, value] of values) {
                    const input = document.createElement('input');
                    Object.assign(input, { type: 'hidden', name, value });
                    form.append(input);
                }
                document.body.append(form);
                form.submit();
            }, { fields, overrides });
            await page.waitForLoadState();
        };
        await page.goto(admin);
        const account = page.locator('#df-account');
        const shopFields = account.locator('form').filter({ has: page.getByRole('button', { name: 'Save local configuration' }) });
        const staleProfile = await main.locator('[name=df_printify_get_revision]').inputValue();
        for (const [field, value] of Object.entries({ shop:'108', account:'fixture-account-b', integration:'fixture-read-b' })) {
            await shopFields.locator('[name="df_printify_profile[' + field + ']"]').fill(value);
        }
        await shopFields.getByRole('button', { name: 'Save local configuration' }).click();
        await panel.getByText(/Shop 108 · account fixture-account-b/).waitFor();
        assert.equal(await panel.locator('pre').count(), 0);
        await panel.getByText(/DIRECT_CONFIGURATION_UNAVAILABLE/).waitFor();
        await post(main, { df_printify_get_revision: staleProfile, df_printify_get_operation: 'SHOPS_READ' });
        await panel.getByText('SCOPE_OR_REVISION_UNAVAILABLE', { exact:true }).waitFor();
        const saved = account.locator('[name=df_printify_switch_profile]');
        await saved.selectOption({ label:'107 · fixture-account · fixture-read' });
        await account.getByRole('button', { name:'Switch local profile' }).click();
        await panel.getByText(/Shop 107 · account fixture-account/).waitFor();
        assert.equal(await panel.locator('pre').count(), 0, 'Previous revision leaked after switching back');

        await main.locator('select[name=df_printify_get_operation]').selectOption('ORDER_READ');
        await main.locator('[name="df_printify_get[order_id]"]').fill('foreign-order');
        await main.getByRole('button', { name: 'Refresh synthetic observation' }).click();
        await panel.getByText('DIRECT_READ_UNAVAILABLE', { exact: true }).waitFor();
        assert.equal(await panel.locator('pre').count(), 0);
        await page.setViewportSize({ width:1280, height:900 });
        await page.getByRole('heading', { name:'Approval queue' }).waitFor();
        await page.getByText(/PRODUCT_UPDATE · PENDING_APPROVAL · revision/).first().waitFor();
        await page.getByText(/PRODUCT_UPDATE · APPROVED_HELD · revision/).first().waitFor();
        const unknown = page.getByText(/MOCKUP_SELECTION_UPDATE · UNKNOWN · revision/).locator('..');
        const summary = unknown.locator('summary');
        await summary.click();
        const refer = unknown.getByRole('button', { name:'Refer for manual reconciliation' });
        const review = refer.locator('..');
        assert.equal(await unknown.getByRole('button', { name:/retry|resend|run approved/i }).count(),0);
        await screenshot('desktop-unknown', summary);
        const checkDenied = async (overrides, reason) => {
            if (!(await refer.isVisible())) await summary.click();
            await post(review, overrides);
            const result = page.getByRole('region', { name:'Operation result' });
            await result.getByText(new RegExp(reason)).waitFor();
            const denial = JSON.parse(await result.innerText());
            assert.equal(denial.state,'DENIED');
            assert.equal(denial.provider_execution_authorized ?? false,false);
            assert.equal(denial.retry_permitted ?? false,false);
            console.log('Held action denied: ' + denial.reason);
        };
        await checkDenied({ df_workspace_nonce:'0000000000', 'df_proposal[decision]':'simulate_held' },
            'INVALID_FORM_OR_SESSION');
        await checkDenied({ df_workspace_revision:'0', 'df_proposal[decision]':'simulate_held' },
            'STALE|CHANGED');
        await checkDenied({ 'df_proposal[decision]':'simulate_held' }, 'FIXTURE_TRANSITION_APPROVAL_MISMATCH');
        if (!(await refer.isVisible())) await summary.click();
        await refer.click();
        await page.goto(admin);
        await summary.click();
        await unknown.getByText('Manual reconciliation required. The outcome remains UNKNOWN.').waitFor();
        await page.setViewportSize({ width:390,height:844 });
        await screenshot('mobile-unknown-referred', summary);
        assert.deepEqual(externalResponses, []);
        console.log('PASS: mobile profile save/switch, stale profile denial, desktop pending/held/UNKNOWN, forged nonce and stale revision denial, UNKNOWN retry denial and durable mobile referral');
        console.log('PASS: mobile product, GPSR, tracking and 11th support request, reopening, redaction and unavailable response. Nonlocal routes rejected: ' + outside);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
