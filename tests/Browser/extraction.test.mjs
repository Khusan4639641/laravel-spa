import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { extractDocument, inspectBlock } from '../../scripts/yandex-extraction.mjs';
let browser;
let page;
before(async () => {
    browser = await chromium.launch({ chromiumSandbox: true });
    page = await browser.newPage();
    await page.route('https://yandex.ru/**', route => route.fulfill({ body: '<html></html>', contentType: 'text/html' }));
    await page.goto('https://yandex.ru/maps/org/demo/123456789/');
});
after(async () => { await browser?.close(); });
const card = (counters = '<meta itemprop="ratingCount" content="1234"><meta itemprop="reviewCount" content="1">') => `
    <section class="business-card-title-view"><h1>Тестовая организация</h1></section>
    <div itemprop="aggregateRating"><meta itemprop="ratingValue" content="4.7">${counters}</div>
    <div class="business-reviews-card-view"><article class="business-review-view" data-review-id="abc123">
        <span class="business-review-view__author"><span itemprop="name">Анна</span></span>
        <time datetime="2026-01-10">10 января 2026</time>
        <meta itemprop="ratingValue" content="5">
        <div class="business-review-view__body-text">Хорошее место. Робот и CAPTCHA в тексте отзыва.</div>
    </article></div>`;
test('extracts exact metrics and one review root, not nested author/body/date nodes', async () => {
    await page.setContent(card());
    const result = await page.evaluate(extractDocument);
    assert.equal(result.organization.title, 'Тестовая организация');
    assert.equal(result.organization.external_id, '123456789');
    assert.equal(result.organization.ratings_count, 1234);
    assert.equal(result.organization.reviews_count, 1);
    assert.equal(result.reviews.length, 1);
    assert.equal(result.reviews[0].author, 'Анна');
    assert.equal(result.reviews[0].rating, 5);
    assert.equal(result.reviews[0].date, '2026-01-10');
    assert.equal(result.meta.reviews_container_found, true);
    assert.equal(await page.evaluate(inspectBlock), false);
});
test('missing or abbreviated counters stay null, never invented zeros', async () => {
    await page.setContent(card('<span class="business-rating-amount-view">1,2 тыс. оценок</span><span class="tabs-select-view__title">Отзывы 1.2K</span>'));
    const result = await page.evaluate(extractDocument);
    assert.equal(result.organization.ratings_count, null);
    assert.equal(result.organization.reviews_count, null);
});
test('counts with nonbreaking spaces and reviews tab badge are exact', async () => {
    await page.setContent(card('<span class="business-rating-amount-view">1 234 оценки</span><span class="tabs-select-view__tab"><span class="tabs-select-view__title">Отзывы</span><span class="tabs-select-view__counter">612</span></span>'));
    const result = await page.evaluate(extractDocument);
    assert.equal(result.organization.ratings_count, 1234);
    assert.equal(result.organization.reviews_count, 612);
});
test('real challenge markup is blocked', async () => {
    await page.setContent('<html><title>Подтвердите, что вы не робот</title><form action="/checkcaptcha"><input></form></html>');
    assert.equal(await page.evaluate(inspectBlock), true);
    assert.equal((await page.evaluate(extractDocument)).meta.organization_found, false);
});
test('review root without rating/body remains invalid for PHP validation', async () => {
    await page.setContent('<h1>Unknown</h1><div class="business-reviews-card-view"><div class="business-review-view"><b>Unexpected markup</b></div></div>');
    const result = await page.evaluate(extractDocument);
    assert.equal(result.reviews[0].rating, null);
    assert.equal(result.reviews[0].text, '');
    assert.equal(result.meta.organization_found, false);
});
