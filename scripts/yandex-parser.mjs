import { chromium } from 'playwright';
import { createHash } from 'node:crypto';
import { extractDocument, inspectBlock } from './yandex-extraction.mjs';

const provided = JSON.parse(process.argv[3] || '{}');
const integer = (key, env, fallback, min, max) => Math.max(min, Math.min(max, Number(provided[key] ?? process.env[env] ?? fallback) || fallback));
const options = {
    timeout: integer('timeout_ms', 'YANDEX_TIMEOUT_MS', 60000, 1000, 120000),
    totalTimeout: integer('total_timeout_ms', 'YANDEX_TOTAL_TIMEOUT_MS', 300000, 5000, 1800000),
    maxReviews: integer('max_reviews', 'YANDEX_MAX_REVIEWS', 600, 1, 2000),
    maxScrolls: integer('max_scrolls', 'YANDEX_MAX_SCROLLS', 150, 1, 500),
    delayMin: integer('request_delay_min_ms', 'YANDEX_REQUEST_DELAY_MIN_MS', 1500, 0, 30000),
    delayMax: integer('request_delay_max_ms', 'YANDEX_REQUEST_DELAY_MAX_MS', 4000, 0, 30000),
};
const allowedHosts = ['yandex.ru', 'yandex.com', 'yandex.uz', 'yandex.kz', 'yandex.by', 'yandex.com.tr'];
const officialHost = host => allowedHosts.includes(host.replace(/^www\./, ''));
const resourceHost = host => officialHost(host) || ['yastatic.net', 'yandex.net', 'yandex.ru', 'yandex.com', 'yandex.uz', 'yandex.kz', 'yandex.by', 'yandex.com.tr'].some(domain => host === domain || host.endsWith(`.${domain}`));
const fail = (code, detail) => { throw Object.assign(new Error(detail), { code }); };
const progress = (step, percent, count = 0) => process.stderr.write(`${JSON.stringify({ type: 'progress', step, progress: percent, reviews_found: count })}\n`);
let browser;
let timer;
let interrupted = false;
let blockedResponse = false;
let reviewNetworkFailure = false;
const delay = async page => {
    const max = Math.max(options.delayMin, options.delayMax);
    await page.waitForTimeout(options.delayMin + Math.floor(Math.random() * (max - options.delayMin + 1)));
};
const cleanup = async () => { await browser?.close().catch(() => {}); };
process.once('SIGTERM', async () => { await cleanup(); process.exit(1); });
process.once('SIGINT', async () => { await cleanup(); process.exit(1); });

try {
    const source = new URL(process.argv[2]);
    if (source.protocol !== 'https:' || !officialHost(source.hostname) || source.port || source.username || source.password
        || !/^\/maps\/org\/(?:[^/]+\/)?\d+\/(?:reviews\/?)?$/.test(source.pathname)) {
        fail('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Invalid organization URL.');
    }
    browser = await chromium.launch({ headless: true, chromiumSandbox: provided.chromium_sandbox ?? process.env.YANDEX_CHROMIUM_SANDBOX !== 'false', timeout: 15000 });
    timer = setTimeout(async () => { interrupted = true; await cleanup(); }, options.totalTimeout);
    const context = await browser.newContext({
        locale: 'ru-RU', viewport: { width: 1366, height: 900 }, serviceWorkers: 'block', acceptDownloads: false,
        ...(provided.user_agent ? { userAgent: provided.user_agent } : {}),
    });
    let requestCount = 0;
    await context.route('**/*', async route => {
        const request = route.request();
        const target = new URL(request.url());
        requestCount++;
        const mainNavigation = request.isNavigationRequest() && request.frame().parentFrame() === null;
        if (requestCount > 2500 || !['http:', 'https:'].includes(target.protocol) || target.port
            || !resourceHost(target.hostname) || (mainNavigation && (!officialHost(target.hostname) || !/^\/(?:maps|showcaptcha|captcha)(?:\/|$)/.test(target.pathname)))
            || ['image', 'media', 'font'].includes(request.resourceType())) {
            await route.abort();
        } else await route.continue();
    });
    const page = await context.newPage();
    context.on('page', popup => { if (popup !== page) popup.close().catch(() => {}); });
    page.setDefaultTimeout(8000);
    page.setDefaultNavigationTimeout(options.timeout);
    page.on('response', response => {
        const request = response.request();
        const relevant = request.isNavigationRequest() || ['xhr', 'fetch'].includes(request.resourceType()) && /review/i.test(new URL(response.url()).pathname);
        if (relevant && [401, 403, 429].includes(response.status())) blockedResponse = true;
        if (relevant && response.status() >= 500) reviewNetworkFailure = true;
    });
    page.on('requestfailed', request => {
        if (['xhr', 'fetch'].includes(request.resourceType()) && /review/i.test(new URL(request.url()).pathname)) reviewNetworkFailure = true;
    });
    const assertHealthy = async () => {
        if (blockedResponse || await page.evaluate(inspectBlock)) fail('YANDEX_BLOCKED', 'Source returned a blocking/challenge signal.');
        if (reviewNetworkFailure) fail('YANDEX_NETWORK_ERROR', 'Review loading request failed.');
        if (requestCount > 2500) fail('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Resource budget exhausted.');
    };
    progress('opening', 8);
    await delay(page);
    const response = await page.goto(source.href, { waitUntil: 'domcontentloaded' });
    await assertHealthy();
    if (!response || response.status() >= 400) fail('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Organization page not found.');
    await page.waitForSelector('h1, form[action*="captcha"], .CheckboxCaptcha', { timeout: options.timeout }).catch(async () => {
        await assertHealthy();
        fail('YANDEX_SOURCE_STRUCTURE_CHANGED', 'No organization heading or valid page content.');
    });
    await delay(page);
    await assertHealthy();
    let initial = await page.evaluate(extractDocument);
    // Navigate to the public reviews tab, not an undocumented JSON endpoint.
    if (!source.pathname.includes('/reviews')) {
        const target = new URL(page.url());
        target.pathname = target.pathname.replace(/\/$/, '') + '/reviews/';
        target.search = '';
        await delay(page);
        await page.goto(target.href, { waitUntil: 'domcontentloaded' });
        await delay(page);
        await assertHealthy();
    }
    await page.waitForSelector('.business-reviews-card-view, .business-review-view, [itemprop="review"], .business-reviews-card-view__empty', { timeout: options.timeout }).catch(async () => {
        await assertHealthy();
        fail('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Expected reviews container not found.');
    });
    const collected = new Map();
    let stale = 0;
    let stopReason;
    let payload;
    for (let iteration = 0; iteration < options.maxScrolls; iteration++) {
        await assertHealthy();
        // Expansions are scoped to review bodies. Do not click arbitrary links named "More".
        const expand = page.locator('.business-review-view__expand, .business-review-view__more');
        for (const button of (await expand.all()).slice(0, 50)) {
            if (await button.isVisible()) await button.click({ timeout: 1000 }).catch(() => {});
        }
        payload = await page.evaluate(extractDocument);
        // Counters may be absent from the reviews tab. Use previously observed exact values only.
        for (const field of ['title', 'rating', 'ratings_count', 'reviews_count']) payload.organization[field] ??= initial.organization[field];
        payload.meta.organization_found ||= initial.meta.organization_found;
        const previousSize = collected.size;
        for (const review of payload.reviews) {
            const key = review.external_id || createHash('sha256').update(JSON.stringify([review.author, review.date, review.rating, review.text])).digest('hex');
            collected.set(key, review);
        }
        const expected = payload.organization.reviews_count;
        progress('loading_reviews', Math.min(80, 15 + Math.floor(collected.size / Math.max(1, Math.min(expected ?? options.maxReviews, options.maxReviews)) * 65)), collected.size);
        if (expected !== null && collected.size >= expected) { stopReason = 'declared_count'; break; }
        if (collected.size >= options.maxReviews) { stopReason = 'limit'; break; }
        stale = collected.size === previousSize ? stale + 1 : 0;
        if (stale >= 6) {
            const loading = await page.locator('.business-reviews-card-view__loader, .business-reviews-card-view .spin2_progress_yes').count();
            if (loading) fail('YANDEX_NETWORK_ERROR', 'Reviews remain in loading state.');
            stopReason = 'exhausted'; break;
        }
        const scrolled = await page.evaluate(() => {
            const review = [...document.querySelectorAll('.business-review-view, [itemprop="review"]')].at(-1);
            if (!review) return false;
            let parent = review.parentElement;
            while (parent && parent !== document.body) {
                if (parent.scrollHeight > parent.clientHeight + 20 && /(auto|scroll)/.test(getComputedStyle(parent).overflowY)) {
                    parent.scrollTop = parent.scrollHeight;
                    return true;
                }
                parent = parent.parentElement;
            }
            review.scrollIntoView({ block: 'end' });
            window.scrollTo(0, document.body.scrollHeight);
            return document.documentElement.scrollHeight > window.innerHeight;
        });
        if (!scrolled && expected > collected.size) fail('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Review scroll container not found.');
        await delay(page);
    }
    if (!stopReason) fail('YANDEX_NETWORK_ERROR', 'Maximum scroll iterations reached before extraction finished.');
    await assertHealthy();
    payload.reviews = [...collected.values()].slice(0, options.maxReviews);
    payload.meta = {
        ...payload.meta, reviews_loaded: payload.reviews.length, stop_reason: stopReason,
        coverage: payload.reviews.length >= payload.organization.reviews_count ? 'full' : 'available_only',
        declared_reviews_count: payload.organization.reviews_count,
    };
    progress('validating', 80, payload.reviews.length);
    process.stdout.write(`${JSON.stringify(payload)}\n`);
} catch (error) {
    const code = error.code?.startsWith('YANDEX_') ? error.code
        : interrupted || error.name === 'TimeoutError' || /net::ERR_|Timeout/i.test(error.message) ? 'YANDEX_NETWORK_ERROR'
        : 'YANDEX_PARSER_UNAVAILABLE';
    // No page HTML, cookies, environment, or raw browser exception text in process output.
    const detail = error.code?.startsWith('YANDEX_') ? error.message
        : interrupted ? 'Total extraction deadline exceeded.' : `${error.name || 'Error'} during browser execution.`;
    process.stdout.write(`${JSON.stringify({ error: { code, detail } })}\n`);
    process.exitCode = 1;
} finally {
    clearTimeout(timer);
    await cleanup();
}
