import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
let browser;
const base = process.env.APP_TEST_URL || 'http://symfony13.local';
before(async () => { browser = await chromium.launch({ chromiumSandbox: true }); });
after(async () => { await browser?.close(); });

test('real Nginx SPA login uses CSRF cookie and session, then logout revokes access', async () => {
    const context = await browser.newContext();
    try {
        const page = await context.newPage();
        await page.goto(base);
        await page.waitForURL('**/login');
        await page.getByLabel('Email', { exact: true }).fill('admin@example.com');
        await page.getByLabel('Password', { exact: true }).fill('password');
        const csrf = page.waitForResponse(response => response.url().endsWith('/sanctum/csrf-cookie'));
        await page.getByRole('button', { name: 'Войти', exact: true }).click();
        assert.equal((await csrf).status(), 204);
        await page.waitForURL('**/settings');
        assert.equal((await context.cookies()).some(cookie => cookie.name === 'XSRF-TOKEN'), true);
        const me = await page.evaluate(async () => (await fetch('/api/me', { headers: { Accept: 'application/json' } })).json());
        assert.equal(me.data.email, 'admin@example.com');
        await page.getByRole('button', { name: 'Выйти', exact: true }).click();
        await page.waitForURL('**/login');
        assert.equal(await page.evaluate(async () => (await fetch('/api/me', { headers: { Accept: 'application/json' } })).status), 401);
    } finally { await context.close(); }
});

test('SPA polls processing to completed, paginates 50 reviews without navigation or new sync, escapes review HTML', async () => {
    const context = await browser.newContext();
    try {
        const page = await context.newPage();
        let organization = null;
        let polls = 0;
        let syncs = 0;
        let navigationCount = 0;
        page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigationCount++; });
        await page.route('**/api/**', async route => {
            const request = route.request(); const url = new URL(request.url());
            const reply = data => route.fulfill({ contentType: 'application/json', body: JSON.stringify(data) });
            if (url.pathname === '/api/me') return reply({ data: { email: 'admin@example.com', name: 'Admin' } });
            if (url.pathname === '/api/organization' && request.method() === 'POST') {
                syncs++;
                return route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ data: { organization_id: 1, parsing_run_id: 10, status: 'pending' } }) });
            }
            if (url.pathname === '/api/organization') return reply({ data: organization });
            if (url.pathname.startsWith('/api/parsing-runs/')) {
                polls++;
                if (polls > 1) organization = { id: 1, title: 'Fixture organization', rating: 4.7, ratings_count: 1000, reviews_count: 55, status: 'ready', last_successful_sync_at: '2026-09-13T10:00:00Z' };
                return reply({ data: { id: 10, status: polls > 1 ? 'completed' : 'processing', progress: polls > 1 ? 100 : 65, current_step: polls > 1 ? 'Синхронизация завершена' : 'Загружаем отзывы', reviews_found: 55, reviews_saved: polls > 1 ? 55 : 0 } });
            }
            if (url.pathname === '/api/organization/reviews') {
                const current = Number(url.searchParams.get('page') || 1);
                return reply({ data: organization ? Array.from({ length: current === 1 ? 50 : 5 }, (_, i) => ({ id: current * 100 + i, author: `Author ${current}-${i}`, text: '<img src=x onerror="window.reviewXss=true">', rating: 5, review_date: '2026-01-10' })) : [], meta: { current_page: current, per_page: 50, total: organization ? 55 : 0, last_page: organization ? 2 : 1 } });
            }
            return reply({ data: null });
        });
        await page.goto(`${base}/settings`);
        await page.getByLabel('URL карточки организации').fill('https://yandex.ru/maps/org/123456789/');
        await page.getByRole('button', { name: 'Синхронизировать', exact: true }).click();
        await page.getByText('Загружаем отзывы', { exact: true }).waitFor();
        await page.getByText('Синхронизация завершена', { exact: true }).waitFor();
        await page.locator('.review-item').nth(49).waitFor();
        assert.equal(await page.locator('.review-item').count(), 50);
        const navigationsBefore = navigationCount;
        const pollsBefore = polls;
        await page.getByTitle('Следующая страница').click();
        await page.getByText('Author 2-0', { exact: true }).waitFor();
        assert.equal(await page.locator('.review-item').count(), 5);
        assert.equal(navigationCount, navigationsBefore);
        assert.equal(syncs, 1);
        assert.equal(await page.evaluate(() => window.reviewXss), undefined);
        await page.waitForTimeout(2300);
        assert.equal(polls, pollsBefore);
        await page.screenshot({ path: 'storage/app/settings-browser-test.png', fullPage: true });
    } finally { await context.close(); }
});

test('blocked run restored after reload shows a safe error and stops polling', async () => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    try {
        const page = await context.newPage();
        let polls = 0;
        const run = { id: 20, status: 'blocked', progress: 8, reviews_found: 0, reviews_saved: 0, can_retry: false,
            error_code: 'YANDEX_BLOCKED', error_message: 'Яндекс.Карты ограничили автоматический доступ или запросили CAPTCHA.' };
        await page.route('**/api/**', route => {
            const path = new URL(route.request().url()).pathname;
            let payload = { data: null };
            if (path === '/api/me') payload = { data: { email: 'admin@example.com' } };
            if (path === '/api/organization') payload = { data: { id: 1, status: 'blocked', latest_run: run, source_url: 'https://yandex.ru/maps/org/123456789/' } };
            if (path === '/api/organization/reviews') payload = { data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 } };
            if (path.startsWith('/api/parsing-runs/')) { polls++; payload = { data: run }; }
            return route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) });
        });
        await page.goto(`${base}/settings`);
        await page.getByText(run.error_message, { exact: true }).waitFor();
        assert.equal(await page.getByRole('button', { name: 'Повторить', exact: true }).count(), 0);
        await page.waitForTimeout(2300);
        assert.equal(polls, 0);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        await page.screenshot({ path: 'storage/app/blocked-mobile-browser-test.png', fullPage: true });
    } finally { await context.close(); }
});
