// Back-office part of the end-to-end tests, driven in a real browser by
// e2e/run.sh: log in, save the plugin settings like a merchant, then select
// the test category and run the sync, which sends one request per batch
// from the page's JavaScript.
//
// Env: SHOP_URL, ADMIN_USER, ADMIN_PASSWD, SYNC_CATEGORY, SYNC_PRODUCTS.
import { chromium } from 'playwright';

const env = (name) => {
    const value = process.env[name];
    if (!value) {
        throw new Error(`${name} is not set`);
    }

    return value;
};

const shopUrl = env('SHOP_URL');
const category = env('SYNC_CATEGORY');
const products = env('SYNC_PRODUCTS');

const browser = await chromium.launch();
const page = await browser.newPage();
page.setDefaultTimeout(60000);

try {
    await page.goto(`${shopUrl}/wp-login.php`);
    await page.locator('#user_login').fill(env('ADMIN_USER'));
    await page.locator('#user_pass').fill(env('ADMIN_PASSWD'));
    await Promise.all([
        page.waitForURL(/wp-admin/),
        page.locator('#wp-submit').click(),
    ]);
    console.log('ok - logged in to the admin');

    // Settings form, as a merchant fills it in
    await page.goto(`${shopUrl}/wp-admin/admin.php?page=pricee-settings`);
    const webhookUrl = await page.locator('input[readonly]').inputValue();
    if (webhookUrl !== `${shopUrl}/wp-json/pricee/v1/webhook`) {
        throw new Error(`unexpected webhook URL shown: ${webhookUrl}`);
    }
    console.log(`ok - webhook URL shown: ${webhookUrl}`);
    await page.locator('input[name="pricee_client_id"]').fill('e2e-client');
    await page.locator('input[name="pricee_api_key"]').fill('e2e-key');
    await page.locator('input[name="pricee_webhook_enabled"]').check();
    await page.locator('input[name="pricee_webhook_secret"]').fill('e2e-webhook-secret');
    await Promise.all([
        page.waitForLoadState('load'),
        page.locator('#submit').click(),
    ]);
    await page.locator('#setting-error-settings_updated').waitFor();
    console.log('ok - settings saved');

    // The saved API key is never written back into the page
    const apiKeyValue = await page.locator('input[name="pricee_api_key"]').inputValue();
    if (apiKeyValue !== '') {
        throw new Error('the saved API key is written into the settings page');
    }
    if (await page.content().then((html) => html.includes('e2e-key'))) {
        throw new Error('the saved API key appears in the page source');
    }
    console.log('ok - saved API key not shown');

    // Saved again with the API key field left empty: the key is kept
    await Promise.all([
        page.waitForLoadState('load'),
        page.locator('#submit').click(),
    ]);
    await page.locator('#setting-error-settings_updated').waitFor();

    // Sync of the test category: more products than one batch
    await page.goto(`${shopUrl}/wp-admin/admin.php?page=pricee-sync`);
    const checkbox = page.locator('.form-check', { hasText: `${category} (${products})` }).locator('input');
    await checkbox.check();
    await page.locator('#pricee-sync-form button[type="submit"]').click();
    const result = page.locator('#pricee-sync-result.notice-success');
    await result.waitFor({ timeout: 120000 });
    const message = (await result.innerText()).trim();
    if (message !== `${products} produits synchronisés`) {
        throw new Error(`unexpected sync result: ${message}`);
    }
    console.log(`ok - sync finished: ${message}`);
} catch (error) {
    await page.screenshot({ path: '/output/failure.png', fullPage: true }).catch(() => {});
    console.error(`FAIL: ${error.message} (page: ${page.url()}, screenshot: e2e/output/failure.png)`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
