const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
    const origin = 'http://127.0.0.1:18276';
    const admin = origin + '/wp-admin/tools.php?page=digiforge-printify-api';
    const browser = await chromium.launch({ executablePath:'/usr/bin/chromium', args:['--no-sandbox'] });
    try {
        const page = await browser.newPage({ viewport:{width:390,height:844}, isMobile:true, hasTouch:true, userAgent:'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36' });
        await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
        await page.goto(admin);
        await page.locator('#user_login').fill('admin');
        await page.locator('#user_pass').fill('local-fixture-only-123');
        await page.locator('#wp-submit').click();
        await page.goto(admin);
        assert(await page.locator("[name=df_printify_get_operation]").evaluate(el => el.getBoundingClientRect().right <= 390));
        await page.locator('[name=df_printify_get_operation]').selectOption('ORDER_READ');
        await page.locator('[name="df_printify_get[order_id]"]').fill('order-1');
        await page.getByRole('button', {name:'Refresh Printify read'}).click();
        await page.getByText('DIRECT_READ_OBSERVED', {exact:true}).waitFor();
        await page.locator('pre').filter({hasText:'tracking_hash'}).waitFor();
        await page.goto(admin);
        await page.locator('pre').filter({hasText:'partially-fulfilled'}).waitFor();
        assert((await page.locator('body').innerText()).includes('fixture-tracking-1'));
        assert(!(await page.locator('body').innerText()).includes('synthetic-operational-not-a-pat'));
        async function read(operation, fields, reason = 'DIRECT_READ_OBSERVED') {
            await page.goto(admin);
            await page.locator('[name=df_printify_get_operation]').selectOption(operation);
            for (const [key, value] of Object.entries(fields)) {
                await page.locator(`[name="df_printify_get[${key}]"]`).fill(value);
            }
            await page.getByRole('button', {name:'Refresh Printify read'}).click();
            await page.getByRole('status').filter({hasText:reason}).waitFor();
        }
        const product = {product_id:'product-1', blueprint_id:'42', provider_id:'7'};
        await read('PRODUCT_SKU_READ', {...product, sku:'19779789605456636343'});
        let projection = JSON.parse(await page.locator('pre').filter({hasText:'19779789605456636343'}).innerText());
        assert.equal(projection.variant_id, 9);
        assert.deepEqual(projection.print_areas[0].variant_ids, [9]);
        assert(!JSON.stringify(projection).includes('other-sku'));

        await read('PRODUCT_ARTWORK_READ', {...product, variant_ids:'9,10', image_id:'asset-1'});
        projection = JSON.parse(await page.locator('pre').filter({hasText:'"placements"'}).innerText());
        assert.equal(projection.image_id, 'asset-1');
        assert.deepEqual(projection.placements[0].variant_ids, [9]);
        assert.equal(projection.placements[0].artwork.metadata.width, 1200);
        assert(!JSON.stringify(projection).includes('private.example.invalid'));
        await read('PRODUCT_ARTWORK_READ', {...product, variant_ids:'9,10', image_id:'foreign-image'}, 'DIRECT_READ_UNAVAILABLE');
        assert.equal(await page.getByText(/Stale prior provider PRODUCT_ARTWORK_READ observation/).count(), 0);
        assert.equal(await page.locator('pre').filter({hasText:'asset-1'}).count(), 0);

        await read('CATALOG_SHIPPING_METHOD_READ', {blueprint_id:'42', provider_id:'7', method:'standard'});
        projection = JSON.parse(await page.locator('pre').filter({hasText:'"shipping_plan_id"'}).innerText());
        assert.equal(projection.rates[0].shipping_plan_id, 'synthetic-standard');
        assert.deepEqual(projection.rates[0].handling_time, {from:2, to:5});
        assert.equal(projection.address_quote, false);

        await read('ORDER_READ', {order_id:'order-error'}, 'PROVIDER_RATE_LIMITED');
        await page.getByText('Provider requested a wait of 7 seconds.').waitFor();
        await page.getByText(/Wait for the indicated provider delay/).waitFor();
        await new Promise(resolve => setTimeout(resolve, 7000));
        await read('ORDER_READ', {order_id:'order-1'});
        await read('ORDER_READ', {order_id:'order-1'});
        await read('ORDER_READ', {order_id:'order-1'});
        const history = page.locator('details').filter({has:page.getByText('Recent read outcomes', {exact:true})});
        assert.equal(await history.locator('li').count(), 8);
        const separate = await browser.newPage();
        try {
            await separate.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
            await separate.goto(admin);
            await separate.locator('#user_login').fill('admin');
            await separate.locator('#user_pass').fill('local-fixture-only-123');
            await separate.locator('#wp-submit').click();
            await separate.goto(admin);
            assert(!(await separate.locator('body').innerText()).includes('fixture-tracking-1'));
            assert.equal(await separate.getByText('Recent read outcomes', {exact:true}).count(), 0);
        } finally { await separate.close(); }
        console.log('Installed SKU binding, linked artwork denial, shipping display, eight-entry history, separate-session suppression and manual 429 recovery passed.');
        const credentialFile = process.env.DF_COEX_CREDENTIAL_FILE;
        assert(credentialFile && credentialFile.startsWith('/tmp/df-printify-coex-'));
        const rotated = JSON.parse(fs.readFileSync(credentialFile,'utf8'));
        rotated.token = 'synthetic-rotated-not-a-pat';
        fs.writeFileSync(credentialFile+'.next',JSON.stringify(rotated),{mode:0o600,flag:'wx'});
        fs.renameSync(credentialFile+'.next',credentialFile);
        await page.locator('[name=df_printify_get_operation]').selectOption('ORDER_READ');
        await page.locator('[name="df_printify_get[order_id]"]').fill('order-1');
        await page.getByRole('button',{name:'Refresh Printify read'}).click();
        await page.getByText('DIRECT_READ_OBSERVED',{exact:true}).waitFor();
        assert(!(await page.locator('body').innerText()).includes(rotated.token));
        fs.unlinkSync(credentialFile);
        await page.goto(admin);
        await page.getByText(/Connection: DIRECT_CONFIGURATION_UNAVAILABLE/).waitFor();
        assert(!(await page.locator('body').innerText()).includes('fixture-tracking-1'));
        console.log('Mobile protected order refresh, reopening, credential rotation and removal passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
