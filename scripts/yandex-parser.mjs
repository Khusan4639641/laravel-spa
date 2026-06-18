import { chromium } from 'playwright';
import crypto from 'node:crypto';

const url = process.argv[2];
const MAX_REVIEWS = Number.parseInt(process.env.YANDEX_MAX_REVIEWS ?? '600', 10);
const MAX_SCROLLS = Number.parseInt(process.env.YANDEX_MAX_SCROLLS ?? '80', 10);
const STALE_SCROLL_LIMIT = Number.parseInt(process.env.YANDEX_STALE_SCROLLS ?? '6', 10);

if (!url) {
    process.stderr.write('Yandex Maps organization URL is required.\n');
    process.exit(1);
}

let browser;

try {
    browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const context = await browser.newContext({
        locale: 'ru-RU',
        userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
        viewport: { width: 1366, height: 900 },
    });

    const page = await context.newPage();
    page.setDefaultTimeout(30000);

    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(2500);

    await assertNotBlocked(page);
    await openReviews(page);
    await expandVisibleTexts(page);
    await scrollReviews(page);
    await expandVisibleTexts(page);
    await assertNotBlocked(page);

    const payload = await page.evaluate(() => {
        const textOf = (root, selectors) => {
            for (const selector of selectors) {
                const node = root.querySelector(selector);
                const value = node?.textContent?.trim();

                if (value) {
                    return value.replace(/\s+/g, ' ');
                }
            }

            return null;
        };

        const attrOf = (root, selectors, attribute) => {
            for (const selector of selectors) {
                const node = root.querySelector(selector);
                const value = node?.getAttribute(attribute)?.trim();

                if (value) {
                    return value;
                }
            }

            return null;
        };

        const normalizeNumber = (value) => {
            if (value === null || value === undefined) {
                return null;
            }

            const normalized = String(value)
                .replace(',', '.')
                .replace(/[^\d.]/g, '');

            if (!normalized) {
                return null;
            }

            const parsed = Number.parseFloat(normalized);

            return Number.isFinite(parsed) ? parsed : null;
        };

        const parseCount = (value) => {
            if (!value) {
                return 0;
            }

            const match = value.replace(/\u00a0/g, ' ').match(/(\d[\d\s.,]*)/);

            if (!match) {
                return 0;
            }

            return Number.parseInt(match[1].replace(/[^\d]/g, ''), 10) || 0;
        };

        const parseRatingFromNode = (root) => {
            const ratingText = textOf(root, [
                '[class*="business-rating-badge-view__rating"]',
                '[class*="business-summary-rating-badge-view__rating"]',
                '[class*="business-card-title-view__rating"]',
                '[aria-label*="рейтинг"]',
                '[aria-label*="ейтинг"]',
                '[aria-label*="rating"]',
            ]);

            const rating = normalizeNumber(ratingText);

            if (rating !== null && rating >= 0 && rating <= 5) {
                return rating;
            }

            const ariaNode = root.querySelector('[aria-label*="5"], [aria-label*="4"], [aria-label*="3"], [aria-label*="2"], [aria-label*="1"]');
            const ariaRating = normalizeNumber(ariaNode?.getAttribute('aria-label'));

            if (ariaRating !== null && ariaRating >= 1 && ariaRating <= 5) {
                return ariaRating;
            }

            return null;
        };

        const reviewSelectors = [
            '[class*="business-review-view"]',
            '[class*="business-reviews-card-view__review"]',
            '[itemprop="review"]',
            '[data-testid*="review"]',
        ];

        const reviewNodes = [...new Set(reviewSelectors.flatMap((selector) => [...document.querySelectorAll(selector)]))]
            .filter((node) => {
                const text = node.textContent?.trim() ?? '';

                return text.length > 20;
            });

        const parseReviewRating = (node) => {
            const explicit = attrOf(node, ['meta[itemprop="ratingValue"]'], 'content');
            const explicitRating = normalizeNumber(explicit);

            if (explicitRating !== null && explicitRating >= 1 && explicitRating <= 5) {
                return Math.round(explicitRating);
            }

            const ariaCandidates = [...node.querySelectorAll('[aria-label]')].map((element) => element.getAttribute('aria-label') ?? '');
            for (const label of ariaCandidates) {
                const match = label.match(/(?:оценка|rating|рейтинг)\s*[:\-]?\s*(\d)/i) ?? label.match(/(\d)\s*(?:из|out of)\s*5/i);

                if (match) {
                    return Math.max(1, Math.min(5, Number.parseInt(match[1], 10)));
                }
            }

            const fullStars = node.querySelectorAll('[class*="star"][class*="_full"], [class*="star"][class*="full"]').length;

            return fullStars >= 1 && fullStars <= 5 ? fullStars : null;
        };

        const title = textOf(document, [
            'h1',
            '[class*="business-card-title-view__title"]',
            '[class*="orgpage-header-view__header"]',
            '[class*="card-title-view__title"]',
        ]) ?? document.title.split(/[—|-]/)[0]?.trim() ?? null;

        const rating = parseRatingFromNode(document);
        const bodyText = document.body?.innerText ?? '';
        const ratingsCountMatch = bodyText.match(/(\d[\d\s.,]*)\s+(?:оценк|rating|ratings|баҳол|bahol)/i);
        const reviewsCountMatch = bodyText.match(/(\d[\d\s.,]*)\s+(?:отзыв|review|reviews|sharh|шарх)/i);

        const reviews = reviewNodes.map((node) => {
            const rawText = node.textContent?.replace(/\s+/g, ' ').trim() ?? '';
            const author = textOf(node, [
                '[class*="business-review-view__author"]',
                '[class*="business-review-view__user-name"]',
                '[class*="user-name"]',
                '[itemprop="author"]',
            ]);
            const rawDate = attrOf(node, ['meta[itemprop="datePublished"]'], 'content') ?? textOf(node, [
                '[class*="business-review-view__date"]',
                '[class*="business-review-view__date"] time',
                'time',
            ]);
            const text = textOf(node, [
                '[class*="business-review-view__body-text"]',
                '[class*="business-review-view__text"]',
                '[itemprop="reviewBody"]',
                '[data-testid*="review-text"]',
            ]) ?? rawText;
            const explicitId = node.getAttribute('data-review-id')
                ?? node.getAttribute('data-id')
                ?? node.id
                ?? null;

            return {
                external_id: explicitId,
                author,
                date: normalizeDate(rawDate),
                text,
                rating: parseReviewRating(node),
                raw: {
                    raw_date: rawDate,
                    raw_text: rawText,
                },
            };
        }).filter((review) => review.text || review.author);

        return {
            organization: {
                title,
                rating,
                ratings_count: parseCount(ratingsCountMatch?.[0]),
                reviews_count: parseCount(reviewsCountMatch?.[0]) || reviews.length,
                external_id: extractExternalId(location.href),
                meta: {
                    url: location.href,
                    collected_reviews: reviews.length,
                },
            },
            reviews,
        };

        function normalizeDate(value) {
            if (!value) {
                return null;
            }

            const input = value.replace(/\u00a0/g, ' ').trim().toLowerCase();
            const iso = input.match(/\d{4}-\d{2}-\d{2}/)?.[0];

            if (iso) {
                return iso;
            }

            const dotted = input.match(/(\d{1,2})\.(\d{1,2})\.(\d{4})/);

            if (dotted) {
                return `${dotted[3]}-${dotted[2].padStart(2, '0')}-${dotted[1].padStart(2, '0')}`;
            }

            const months = {
                января: '01',
                февраль: '02',
                февраля: '02',
                марта: '03',
                апреля: '04',
                мая: '05',
                июня: '06',
                июля: '07',
                августа: '08',
                сентября: '09',
                октября: '10',
                ноября: '11',
                декабря: '12',
                january: '01',
                february: '02',
                march: '03',
                april: '04',
                may: '05',
                june: '06',
                july: '07',
                august: '08',
                september: '09',
                october: '10',
                november: '11',
                december: '12',
            };
            const textual = input.match(/(\d{1,2})\s+([a-zа-яё]+)\s+(\d{4})/i);

            if (textual && months[textual[2]]) {
                return `${textual[3]}-${months[textual[2]]}-${textual[1].padStart(2, '0')}`;
            }

            return null;
        }

        function extractExternalId(value) {
            const match = value.match(/\/org\/[^/]+\/(\d+)/);

            return match?.[1] ?? null;
        }
    });

    const dedupedReviews = dedupeReviews(payload.reviews).slice(0, MAX_REVIEWS);
    payload.reviews = dedupedReviews;

    if (!payload.organization.title && payload.organization.rating === null && dedupedReviews.length === 0) {
        fail('Could not find organization data. Yandex markup may have changed or the page is unavailable.');
    }

    process.stdout.write(`${JSON.stringify(payload)}\n`);
} catch (error) {
    const message = normalizeFailure(error);
    process.stderr.write(`${message}\n`);
    process.exitCode = 1;
} finally {
    await browser?.close().catch(() => {});
}

async function openReviews(page) {
    const reviewTexts = [
        /Отзывы/i,
        /Все отзывы/i,
        /Reviews/i,
        /All reviews/i,
        /Sharhlar/i,
    ];

    for (const text of reviewTexts) {
        const locator = page.getByText(text).first();

        if (await locator.count().catch(() => 0)) {
            await locator.click({ timeout: 5000 }).catch(() => {});
            await page.waitForTimeout(1500);
            return;
        }
    }

    const current = new URL(page.url());
    current.searchParams.set('tab', 'reviews');
    await page.goto(current.toString(), { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(2000);
}

async function scrollReviews(page) {
    let previousCount = 0;
    let staleScrolls = 0;

    for (let i = 0; i < MAX_SCROLLS; i += 1) {
        const count = await page.evaluate(() => {
            const selectors = [
                '[class*="business-review-view"]',
                '[class*="business-reviews-card-view__review"]',
                '[itemprop="review"]',
                '[data-testid*="review"]',
            ];

            return new Set(selectors.flatMap((selector) => [...document.querySelectorAll(selector)])).size;
        });

        if (count >= MAX_REVIEWS) {
            break;
        }

        if (count <= previousCount) {
            staleScrolls += 1;
        } else {
            staleScrolls = 0;
            previousCount = count;
        }

        if (staleScrolls >= STALE_SCROLL_LIMIT) {
            break;
        }

        await page.evaluate(() => {
            const review = document.querySelector('[class*="business-review-view"], [itemprop="review"], [data-testid*="review"]');
            let container = review?.parentElement ?? null;

            while (container && container !== document.body) {
                const style = window.getComputedStyle(container);
                const canScroll = container.scrollHeight > container.clientHeight + 80;

                if (canScroll && /(auto|scroll)/.test(`${style.overflowY}${style.overflow}`)) {
                    container.scrollTop = container.scrollHeight;
                    return;
                }

                container = container.parentElement;
            }

            window.scrollTo(0, document.body.scrollHeight);
        });

        await page.mouse.wheel(0, 2200).catch(() => {});
        await page.waitForTimeout(900);
        await expandVisibleTexts(page);
    }
}

async function expandVisibleTexts(page) {
    const patterns = [/Ещё/i, /Показать полностью/i, /Read more/i, /More/i, /Yana/i];

    for (const pattern of patterns) {
        const matches = await page.getByText(pattern).all().catch(() => []);

        for (const match of matches.slice(0, 20)) {
            await match.click({ timeout: 1000 }).catch(() => {});
        }
    }
}

async function assertNotBlocked(page) {
    const blocked = await page.evaluate(() => {
        const text = document.body?.innerText?.toLowerCase() ?? '';

        return location.href.includes('showcaptcha')
            || text.includes('captcha')
            || text.includes('капча')
            || text.includes('робот')
            || text.includes('robot')
            || text.includes('доступ ограничен')
            || text.includes('access denied');
    });

    if (blocked) {
        fail('Yandex blocked automated access or captcha required');
    }
}

function dedupeReviews(reviews) {
    const seen = new Set();
    const result = [];

    for (const review of reviews) {
        const key = review.external_id || crypto
            .createHash('sha256')
            .update([review.author, review.date, review.text, review.rating].join('|'))
            .digest('hex');

        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        result.push(review);
    }

    return result;
}

function normalizeFailure(error) {
    const message = String(error?.message ?? error ?? '');

    if (message.includes('Executable doesn\'t exist') || message.includes('browserType.launch')) {
        return 'Playwright Chromium is not installed. Run: npx playwright install chromium';
    }

    if (message.includes('Yandex blocked automated access') || message.toLowerCase().includes('captcha')) {
        return 'Yandex blocked automated access or captcha required';
    }

    if (message.toLowerCase().includes('timeout')) {
        return 'Yandex Maps page did not load in time.';
    }

    return message.split('\n')[0].slice(0, 300) || 'Yandex Maps parser failed.';
}

function fail(message) {
    throw new Error(message);
}
