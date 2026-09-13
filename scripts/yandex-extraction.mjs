// Runs inside the page. Keep extraction independent from orchestration and HTTP transport.
export function extractDocument() {
    const text = (root, selector) => root.querySelector(selector)?.textContent?.trim() ?? null;
    const attribute = (root, selector, name) => root.querySelector(selector)?.getAttribute(name) ?? null;
    const exactCount = (value) => {
        if (value === null || value === undefined) return null;
        const raw = String(value).replace(/[\s\u00a0\u202f]/g, '');
        // Do not turn 1.2K / 1,2 тыс. into 12. Reject approximate or ambiguous counters.
        return /^\d+$/.test(raw) ? Number(raw) : null;
    };
    const countIn = (value, label) => {
        const match = value?.match(new RegExp(`(\\d[\\d\\s\\u00a0\\u202f]*)\\s+${label}`, 'i'));
        if (!match || /[.,\d]$/.test(value.slice(0, match.index))) return null;
        return exactCount(match[1]);
    };
    const dateValue = (value) => {
        if (!value) return null;
        const iso = value.match(/^\d{4}-\d{2}-\d{2}/)?.[0];
        if (iso) return iso;
        const raw = value.toLowerCase().trim();
        const months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        const match = raw.match(/^(\d{1,2})\s+([а-я]+)(?:\s+(\d{4}))?/);
        if (!match || !months.includes(match[2])) return null;
        const now = new Date();
        const month = months.indexOf(match[2]);
        let year = Number(match[3] || now.getFullYear());
        if (!match[3] && new Date(year, month, Number(match[1])) > now) year--;
        return `${year}-${String(month + 1).padStart(2, '0')}-${match[1].padStart(2, '0')}`;
    };
    const jsonLd = [];
    const visit = (value) => {
        if (Array.isArray(value)) value.forEach(visit);
        else if (value && typeof value === 'object') {
            if (value.aggregateRating && value.name) jsonLd.push(value);
            if (value['@graph']) visit(value['@graph']);
        }
    };
    for (const script of document.querySelectorAll('script[type="application/ld+json"]')) {
        try { visit(JSON.parse(script.textContent)); } catch { /* DOM validation decides whether this is usable. */ }
    }
    const schema = jsonLd[0];
    const title = text(document, 'h1.orgpage-header-view__header, h1.business-card-title-view__title, .business-card-title-view__title, h1');
    const ratingRaw = attribute(document, '[itemprop="aggregateRating"] [itemprop="ratingValue"]', 'content')
        ?? text(document, '.business-summary-rating-badge-view__rating, .business-rating-badge-view__rating-text, .business-rating-badge-view__rating')
        ?? schema?.aggregateRating?.ratingValue;
    const rating = ratingRaw === null || ratingRaw === undefined ? null : Number(String(ratingRaw).trim().replace(',', '.'));
    const counters = [...document.querySelectorAll('.business-summary-rating-badge-view__rating-count, .business-rating-amount-view, .business-header-rating-view__text, .tabs-select-view__title, .tabs-select-view__counter, .tabs-select-view__tab, .business-reviews-card-view__header')];
    let ratingsCount = exactCount(attribute(document, '[itemprop="ratingCount"]', 'content') ?? schema?.aggregateRating?.ratingCount);
    let reviewsCount = exactCount(attribute(document, '[itemprop="reviewCount"]', 'content') ?? schema?.aggregateRating?.reviewCount);
    for (const node of counters) {
        // Limit text scanning to organization controls; never inspect arbitrary review text for counts.
        const value = node.getAttribute('aria-label') || node.textContent || '';
        ratingsCount ??= countIn(value, '(?:оцен(?:ка|ки|ок)|ratings?)');
        reviewsCount ??= countIn(value, '(?:отзыв(?:а|ов)?|reviews?)');
        if (/^(?:Отзывы|Reviews)\s*/i.test(value.trim())) {
            reviewsCount ??= exactCount(value.trim().replace(/^(?:Отзывы|Reviews)\s*/i, ''));
            reviewsCount ??= exactCount(text(node.parentElement, '.tabs-select-view__counter'));
        }
    }
    const emptyReviews = document.querySelector('.business-reviews-card-view__empty, .business-reviews-card-view__no-reviews');
    if (emptyReviews && /нет отзывов|no reviews|оставьте первый отзыв/i.test(emptyReviews.textContent)) reviewsCount ??= 0;
    const unrated = document.querySelector('.business-header-rating-view, .business-rating-amount-view');
    if (unrated && /нет оценок|no ratings/i.test(unrated.textContent)) ratingsCount ??= 0;
    // Exact roots only. A substring selector also matches author/date/body and manufactures reviews.
    const roots = [...document.querySelectorAll('.business-review-view, [itemprop="review"]')]
        .filter(node => !node.parentElement?.closest('.business-review-view, [itemprop="review"]'));
    const reviews = roots.map(node => {
        const author = text(node, '.business-review-view__author-name, .business-review-view__author [itemprop="name"], .business-review-view__author, [itemprop="author"]');
        const rawDate = attribute(node, '[itemprop="datePublished"]', 'content')
            ?? attribute(node, 'time', 'datetime') ?? text(node, '.business-review-view__date, time');
        const body = node.querySelector('.business-review-view__body-text, .business-review-view__text, [itemprop="reviewBody"]');
        const explicit = attribute(node, '[itemprop="ratingValue"]', 'content');
        const label = node.querySelector('.business-rating-badge-view, [role="img"][aria-label]')?.getAttribute('aria-label');
        const match = label?.match(/(?:Оценка|Рейтинг|Rating)\s*:?\s*([1-5])|([1-5])\s*(?:из|out of)\s*5/i);
        const stars = node.querySelectorAll('.business-rating-badge-view__star._full').length;
        const rating = explicit !== null ? Number(explicit) : match ? Number(match[1] ?? match[2]) : stars || null;
        const externalId = node.getAttribute('data-review-id') || null;
        return {
            external_id: externalId, author, date: dateValue(rawDate), text: body?.textContent?.trim() ?? '', rating,
            raw: { raw_date: rawDate, date_inferred_year: Boolean(rawDate && !/\d{4}/.test(rawDate)), body_found: Boolean(body) },
        };
    });
    return {
        organization: {
            external_id: location.pathname.match(/\/org\/(?:[^/]+\/)?(\d+)(?:\/|$)/)?.[1] ?? null,
            title, rating, ratings_count: ratingsCount, reviews_count: reviewsCount,
        },
        reviews,
        meta: {
            source: 'yandex_maps', organization_found: Boolean(title && document.querySelector('.business-card-title-view, .orgpage-header-view, [itemtype*="LocalBusiness"], [itemprop="aggregateRating"]')),
            reviews_container_found: Boolean(document.querySelector('.business-reviews-card-view, .business-reviews-card-view__reviews, [itemprop="review"]') || emptyReviews),
            html_length: document.documentElement?.outerHTML.length ?? 0,
        },
    };
}

export function inspectBlock() {
    const title = document.title.toLowerCase();
    const heading = document.querySelector('h1')?.textContent?.toLowerCase() ?? '';
    const challenge = document.querySelector('form[action*="checkcaptcha"], form[action*="showcaptcha"], .CheckboxCaptcha, .AdvancedCaptcha, #captcha-container');
    // Review text may itself mention robots/CAPTCHA; it is not a blocking signal.
    return Boolean(challenge || /showcaptcha|\/captcha(?:\/|\?)/i.test(location.href)
        || /access denied|доступ ограничен|подтвердите, что вы не робот|are you a robot|robot or human|ой!/.test(`${title} ${heading}`));
}
