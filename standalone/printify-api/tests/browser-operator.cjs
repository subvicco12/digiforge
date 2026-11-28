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
        await main.locator('select[name=df_printify_get_operation]').selectOption('ORDER_READ');
        await main.locator('[name="df_printify_get[order_id]"]').fill('foreign-order');
        await main.getByRole('button', { name: 'Refresh synthetic observation' }).click();
        await panel.getByText('DIRECT_READ_UNAVAILABLE', { exact: true }).waitFor();
        assert.equal(await panel.locator('pre').count(), 0);
        assert.deepEqual(externalResponses, []);
        console.log('PASS: mobile product, GPSR, tracking and 11th support request, reopening, redaction and unavailable response. Nonlocal routes rejected: ' + outside);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
