# Project

Production-oriented prototype интеграции с публичными карточками Яндекс.Карт для тестового задания Laravel/PHP. Два экрана: вход и настройки организации с результатами фоновой синхронизации. Локальный адрес: **http://symfony13.local**. Регистрации нет.

Проект до доработки уже содержал Laravel, Sanctum, Vue, таблицы организаций/отзывов и синхронный Playwright-парсер. Сохранены приложение, стили, аутентификация, компоненты отзывов и пагинации. Парсинг перенесён в очередь; исправлены валидация результата, идентичность отзывов и сохранение истории. Старые миграции не переписаны. Nginx не изменён.

# Stack

- Laravel 13, PHP 8.5, Sanctum 4, Symfony Process.
- Vue 3, Composition API, Vue Router, Axios, Vite 8.
- MySQL; database queue и database cache с атомарными locks.
- Node.js 22+, Playwright и соответствующая установленной версии Chromium Headless Shell.
- PHPUnit 12; Node test runner + Playwright для DOM и browser tests.

# Local installation

В существующем окружении зависимости и MySQL уже доступны. Все команды выполняются **в `/var/www/project13`**. Не заменяйте существующий `.env` и APP_KEY.

```bash
cd /var/www/project13
composer install
# Только для новой установки, если .env ещё нет:
# cp .env.example .env
# php artisan key:generate
npm install --cache "$PWD/storage/app/npm-cache"
PLAYWRIGHT_BROWSERS_PATH="$PWD/storage/app/playwright" \
  npm_config_cache="$PWD/storage/app/npm-cache" npx playwright install chromium --only-shell
php artisan migrate
php artisan db:seed
npm run build
php artisan optimize:clear
```

Откройте http://symfony13.local. Данные входа для новой локальной установки: `admin@example.com` / `password`.

Миграции изменяют схему существующей БД без очистки таблиц. Нельзя применять `migrate:fresh`, `db:wipe` или удалять production-данные. Перед production-миграциями нужна штатная резервная копия. Seeder использует `firstOrCreate`: существующий пароль администратора не сбрасывается.

Если Chromium сообщает о недостающих системных библиотеках, установите зависимости ОС по [документации Playwright](https://playwright.dev/docs/browsers). Установка системных пакетов не включена в автоматические команды проекта. В проверенном локальном окружении дополнительные пакеты не понадобились.

# Environment

В `.env.example` перечислены настройки, включая:

| Настройка | По умолчанию | Значение |
|---|---:|---|
| `APP_URL` | `http://symfony13.local` | Origin приложения |
| `DB_DATABASE` | `laravel13` | Локальная MySQL БД |
| `DB_USERNAME` / `DB_PASSWORD` | `root` / `root` | Только локальное окружение |
| `QUEUE_CONNECTION` | `database` | Основная очередь |
| `CACHE_STORE` | `database` | Общие атомарные блокировки |
| `SANCTUM_STATEFUL_DOMAINS` | `symfony13.local,localhost,127.0.0.1` | Доверенные SPA origins, без схемы |
| `SESSION_DOMAIN` | `symfony13.local` | Домен cookies |
| `YANDEX_REQUEST_DELAY_MIN_MS` | 1500 | Минимальная задержка загрузки |
| `YANDEX_REQUEST_DELAY_MAX_MS` | 4000 | Максимальная задержка загрузки |
| `YANDEX_MAX_RETRIES` | 3 | **Общее число попыток парсера**, включая первую |
| `YANDEX_TIMEOUT_MS` | 60000 | Таймаут навигации / ожидания DOM |
| `YANDEX_TOTAL_TIMEOUT_MS` | 300000 | Общий лимит extraction |
| `YANDEX_MAX_REVIEWS` | 600 | Лимит отзывов за один запуск |
| `YANDEX_MAX_SCROLLS` | 150 | Максимум итераций прокрутки |
| `YANDEX_MAX_CONCURRENT_JOBS` | 2 | Общий максимум активных браузеров |
| `YANDEX_CHROMIUM_SANDBOX` | true | Chromium sandbox |
| `YANDEX_NODE_BINARY` | node | Исполняемый файл Node |
| `YANDEX_USER_AGENT` | пусто | Штатный User-Agent установленного Chromium |
| `PLAYWRIGHT_BROWSERS_PATH` | `storage/app/playwright` | По умолчанию вычисляется как абсолютный путь проекта |
| `DB_QUEUE_RETRY_AFTER` | 390 | Время до повторной выдачи зарезервированного job |

`config/yandex.php` ограничивает диапазоны. `config/queue.php` дополнительно гарантирует `retry_after >= total_timeout + 90 секунд`. После изменения конфигурации перезапустите workers. Значения `.env` не читаются напрямую из сервисов; `config:cache` поддерживается.

# Database

Существующие таблицы `users`, `organizations`, `organization_reviews`, `jobs`, `failed_jobs`, `cache`, `cache_locks`, `sessions` используются повторно.

Добавлены две миграции:

- `2026_09_13_000001_add_organization_sync_tracking`: переименовывает legacy-поля организации (`yandex_external_id` → `external_id`, `scrape_status` → `status`, `scrape_error` → `last_error`, `last_scraped_at` → `last_successful_sync_at`), добавляет время начала/окончания синхронизации, индексы и текущую организацию пользователя. Ошибочная legacy-дата не выдаётся за успешный sync. Старые незавершённые синхронные состояния становятся `idle`.
- `2026_09_13_000002_create_parsing_runs_and_organization_snapshots`: отдельные запуски и snapshots, внешние ключи и индексы.

Индексы: organization `user_id`, `external_id`, `status`; review `organization_id`, `content_hash`, `external_id`, `(organization_id, review_date, id)`; run `(organization_id, status)` и `status`; snapshot `(organization_id, id)` и unique `parsing_run_id`. Существующие unique `(organization_id, external_id)` и `(organization_id, content_hash)` сохранены.

При выборе другой карточки создаётся/выбирается отдельная организация, `users.current_organization_id` переключается. Предыдущая организация, её отзывы и snapshots остаются в БД. Повторный выбор той же карточки на другом поддерживаемом домене использует её внешний ID. Отдельный UI управления списком прежних организаций не реализован: их можно снова выбрать по ссылке.

# Queue

**Worker обязателен.** Запускайте в отдельном терминале:

```bash
php artisan queue:work database --queue=yandex,default --sleep=1 --timeout=330
```

Для второго worker выполните ту же команду ещё в одном терминале. Для однократной проверки:

```bash
php artisan queue:work database --queue=yandex --once --sleep=0
```

Парсинг всегда использует database connection и очередь `yandex`, даже если для других задач проекта задан иной `QUEUE_CONNECTION`. Благодаря этому создание run и вставка job в ту же MySQL БД происходят в одной транзакции. Ошибка enqueue откатывает run и изменение текущей организации. Redis **не нужен** для локального запуска.

# Frontend

```bash
npm run dev
# или статическая production-сборка для существующего Nginx:
npm run build
```

Vue Router: `/login` и `/settings`, `/` перенаправляет по состоянию сессии. Axios использует cookies и XSRF, таймаут HTTP 15 секунд. Парсинг не занимает HTTP-запрос. URL проверяется backend Form Request; Vue выводит validation errors и различает ошибку сессии, rate limit и сбой соединения.

`useOrganization` загружает сохранённые данные и страницы отзывов. `useParsing` выполняет неперекрывающиеся запросы состояния через 2 секунды, отменяет их при unmount, восстанавливает наблюдение по `latest_run`. Временный сбой polling повторяется с задержкой, после 5 ошибок доступна кнопка обновления статуса. Polling не запускает парсер.

`OrganizationCard`, `SyncStatus`, `ReviewsList`, `Pagination`, `ErrorMessage`, `LoadingState` отвечают за отдельные части экрана. HTML из отзывов выводится как текст, `v-html` не используется. Показываются последняя успешная дата, объявленное число отзывов, размер сохранённой истории и покрытие extraction.

# Authentication

Cookie-based SPA authentication через Sanctum `statefulApi()`:

1. `GET /sanctum/csrf-cookie`.
2. `POST /api/login` с email/password и X-XSRF-TOKEN.
3. `GET /api/me`.
4. `POST /api/logout` инвалидирует сессию и обновляет CSRF token.

При входе regenerates session ID. Protected endpoints используют `auth:sanctum`; personal access tokens отключены, регистрации нет. Login ограничен 5 попытками в минуту на email+IP и 20 на IP; sync — 6 запросов в минуту на пользователя. Все запросы организаций, reviews, runs и snapshots ограничены владельцем. Переданный `user_id`, `organization_id` или `status` не используется для назначения прав.

# Yandex parser

Принимаются полные ссылки `/maps/org/<slug>/<numeric_id>/`, `/maps/org/<numeric_id>/` и `/reviews/` на `yandex.ru`, `yandex.com`, `yandex.uz`, `yandex.kz`, `yandex.by`, `yandex.com.tr`, включая `www`. Query parameters принимаются и удаляются при нормализации; источник сохраняется отдельно. Произвольные поддомены, порты, credentials, другие пути, короткие/редиректные ссылки отклоняются.

`scripts/yandex-parser.mjs` запускает Chromium, открывает карточку и публичную вкладку отзывов, ждёт DOM, раскрывает тексты внутри отзывов и прокручивает фактический контейнер. `scripts/yandex-extraction.mjs` извлекает строго корневые review-элементы; вложенные автор, дата и текст не становятся отдельными отзывами. JSON-LD и DOM используются для точных счётчиков. Сокращения `1.2K` / `1,2 тыс.` **не переводятся в приблизительное число**; если точных значений нет, результат отклоняется.

Отзывы накапливаются между итерациями, чтобы виртуализация DOM не теряла уже увиденные записи. Остановка: получено объявленное количество, достигнут настроенный лимит, либо 6 итераций без новых отзывов при доступном scroll-контейнере и отсутствии сигнала загрузки/ошибки. Лимит итераций или общий timeout дают ошибку, а не успешный неполный ответ.

Если источник перестал отдавать новые отзывы при корректной странице, возможен результат `coverage=available_only`, `stop_reason=exhausted`. Он означает **все доступные в текущем браузерном проходе**, а не все отзывы Яндекса. Лимит 600 тоже отражается как `available_only`, если Яндекс объявил больше. Ноль вместо ожидаемых отзывов всегда ошибка. Явный сбой загрузки reviews всегда ошибка; информация организации отдельно не публикуется как успешный sync.

# Why Playwright

В prototype выбран браузер, потому что отзывы зависят от выполнения JavaScript и ленивой загрузки. Мы используем публичное представление карточки и не воспроизводим недокументированные подписи запросов. Это удобная стартовая точка для заменяемого адаптера, но она не гарантирует доступность источника или отсутствие CAPTCHA.

# Playwright vs internal Yandex requests

| Подход | Преимущества | Недостатки |
|---|---|---|
| Internal JSON/API extraction | Быстрее, меньше CPU/RAM, удобнее большие объёмы | Недокументированный контракт меняется; tokens, request signatures, зависимость от внутренних endpoint |
| Headless browser | Выполняет JS, поддерживает lazy loading, ближе к поведению публичного интерфейса | Дороже по CPU/RAM, медленнее, нужно обновлять Chromium и селекторы, остаётся anti-bot риск |

Будущий `InternalApiYandexMapsParser` реализует тот же интерфейс и возвращает тот же валидируемый результат. Orchestration и persistence менять не требуется.

# Parser architecture

```text
Form Request → OrganizationSyncScheduler → parsing_runs + database job
                                                   ↓
ParseYandexOrganizationJob → OrganizationSyncService
                                     ↓
                YandexMapsParserInterface
                                     ↓
           PlaywrightYandexMapsParser → Symfony Process(arguments array)
                                     ↓
          Node + Chromium → JSON stdout / progress JSONL stderr
                                     ↓
       ResultValidator → DTO → transaction: organization + reviews + snapshot + run
```

Controller только валидирует вход через Form Request и вызывает scheduler. Job управляет выполнением, middleware, release/fail. Service отвечает за orchestration и транзакционное сохранение. `ReviewPersister` разрешает идентичность и выполняет batch upsert. `ParsedYandexResult::fromArray` проверяет сырые данные **до** преобразования типов; service повторно проверяет результат и соответствие ID исходной карточке.

# Detecting source changes

`YandexMapsResultValidator` проверяет title, organization ID, rating 1–5 (nullable только когда нет оценок), целые неотрицательные точные counters, признаки карточки и reviews-контейнера, непустой HTML, схему reviews, даты, rating и наблюдаемое количество загруженных отзывов. Отсутствующие счётчики не подменяются нулями, rating не обрезается до допустимого диапазона.

Нет карточки, изменилась схема, не найден контейнер или при положительном reviews_count отсутствуют отзывы → `failed`, `YANDEX_SOURCE_STRUCTURE_CHANGED`, сообщение пользователю и технический контекст в Laravel log. Для таких ошибок автоматических повторов нет.

# Anti-ban strategy

- Случайная задержка 1500–4000 мс перед навигацией и следующей загрузкой при прокрутке.
- Общие cache locks ограничивают число браузеров; отдельная очередь изолирует тяжёлые задачи.
- Только временные сетевые ошибки повторяются с возрастающим backoff. Никаких бесконечных повторов.
- HTTP 401/403/429 на navigation/review-запросах, CAPTCHA-формы, challenge URL и явные блокирующие заголовки → `YANDEX_BLOCKED`, status `blocked`, немедленное прекращение retries. Слово «робот» внутри обычного отзыва блокировкой не считается.
- Штатный User-Agent версии Chromium; настройка доступна для согласованного production-профиля, без фиктивной старой версии браузера.
- Proxy pool — возможное production-расширение для согласованных регионов и контроля egress. В этом prototype его нет; ротация для агрессивного обхода CAPTCHA не реализована.
- CAPTCHA не решается и не обходится. UI не предлагает автоматическое повторение blocked-run; после устранения причины оператор может вручную запустить новый sync.

В browser context отключены downloads, service workers, ненужные images/fonts/media и посторонние hosts. Main-frame redirects допускаются только на известных Яндекс-доменах и maps/challenge-путях. На запуск действует лимит 2500 ресурсов, таймаут, максимум scrolls и ограничение объёма JSON (32 MiB). Chromium sandbox включён. В production дополнительно ограничьте egress на уровне сети, CPU/RAM и PID через systemd/container policies.

# Queue architecture

50 организаций × 600 отзывов = до 30 000 отзывов. Держать Nginx/PHP-FPM запросы на время загрузки нельзя: это исчерпает PHP workers и оборвётся по proxy timeout.

```text
API → database queue → 2 workers → Playwright → transaction in MySQL
```

Даже если запустить больше workers, middleware выдаёт не более `YANDEX_MAX_CONCURRENT_JOBS` общих browser slots. Отдельный lock на parsing_run защищает от одновременной доставки одного job. Блокировка строки пользователя сериализует enqueue, включая первую организацию. Повторный pending/processing sync возвращает 409.

Locks должны храниться в **общем** database/Redis cache. `array`/локальный file cache не подходит для нескольких процессов/серверов. Количество работников нельзя увеличивать без контроля RAM, CPU, скорости источника и блокировок. TTL slot lock = job timeout + 30 секунд; освобождение выполняется в finally, после аварийной остановки — по TTL.

Production: Redis предпочтителен для locks, мониторинга и очередей с высокой нагрузкой. Здесь database queue выбрана ради атомарного enqueue вместе с run. Перед переходом самой очереди на Redis потребуется outbox/dispatcher или другой способ восстановления разрыва между DB commit и enqueue; просто поменять переменную окружения недостаточно. Redis cache уже можно использовать, не меняя domain service.

# Retry/backoff

`YANDEX_MAX_RETRIES=3` означает максимум **трёх вызовов парсера**. Задержки: 10, 30, затем 120 секунд для последующих разрешённых конфигурацией попыток. При стандартных трёх попытках используются 10 и 30 секунд. Сетевой timeout / ошибка загрузки / 5xx повторяется; CAPTCHA, source change и отсутствие Chromium завершаются сразу.

`parsing_runs.attempt` считает фактические попытки parser. Ожидание browser slot через `release(15)` не тратит этот лимит. Laravel reservations дополнительно ограничены абсолютным `retryUntil` (6 часов от enqueue); при наличии deadline Laravel использует его приоритетно относительно `$tries`. Это ограничивает время ожидания перегруженной очереди, не превращая 50 ожидающих карточек в ошибки после трёх выдач.

Node deadline 300 s < Symfony Process 315 s < job timeout 330 s < slot TTL 360 s < database retry_after 390 s. Hard timeout вызывает `failed()` и `failOnTimeout`; старые успешные данные сохраняются. `yandex:recover-stale` раз в минуту помечает run, оставленный аварийно погибшим worker, после безопасного интервала; pending старше 6 часов также завершается ошибкой. Завершённые jobs повторно не парсятся.

# Progress tracking

Node пишет события progress в stderr в JSONL, итоговый JSON — в stdout. Symfony Process читает события во время выполнения. Реальный `reviews_found` меняется при extraction, `reviews_saved` остаётся 0 до commit всей транзакции. Процент — оценка этапа, не гарантия оставшегося времени. После commit: 100%, completed. `GET /api/organization` содержит `latest_run`, чтобы восстановить polling после refresh.

# Idempotency

Повторная обработка completed/failed/blocked run не выполняет парсер. Snapshot unique по parsing_run_id. Сохранение organization, upsert reviews, snapshot и completed state выполняется в одной короткой транзакции **после** внешнего запроса. Ошибка persistence откатывает весь новый результат.

# Review deduplication

Основной ключ — `(organization_id, external_id)` при наличии стабильного ID. Fallback — SHA-256 JSON-массива `[organization_id, normalized_author, date, rating, normalized_text]`: trim, приведение регистра и схлопывание пробелов. Внешний ID не входит в content hash, поэтому его появление позже не создаёт дубль неизменённого отзыва.

Существующие ключи загружаются одним запросом, затем выполняется upsert пачками по 100, без SELECT на каждый отзыв. Пагинация — indexed SQL, 50 по умолчанию и максимум. Чужие reviews не подгружаются.

Контрольный тест: 600 отзывов → 605, из прежних 10 изменены → **605 записей и 10 обновлений**. Старые отсутствующие в новом проходе отзывы не удаляются. Поэтому размер накопленной истории может превышать текущее число отзывов на Яндексе.

Без стабильного ID изменение текста меняет hash и считается новой исторической записью; уверенно связать такие редакции невозможно. Не применяется опасное fuzzy-объединение отзывов разных людей. Версионность каждого изменения текста не реализована: стабильный ID обновляет текущую сохранённую версию.

# Snapshots / history

Каждый успешный run создаёт snapshot title/rating/ratings_count/reviews_count. `payload.changes` содержит `from` / `to`, `payload.extraction` — метаданные покрытия.

Тест: `4.6 → 4.7`, `1000 → 1050`, `580 → 593` создаёт 2 snapshots. `GET /api/organization/snapshots` отдаёт историю текущей организации по 20 записей. UI временной шкалы не добавлен. При неуспешном sync snapshot не создаётся, прежние успешные значения и отзывы остаются доступны.

# Error handling

| Endpoint | Ответ |
|---|---|
| `POST /api/login` | 200 user; 401 неверные credentials; 422 поля |
| `GET /api/me` | 200 user / 401 |
| `POST /api/logout` | 200 / 401 |
| `GET /api/organization` | 200 `{data: organization|null}` |
| `POST /api/organization` | 202 `{data: {organization_id, parsing_run_id, status}}` |
| `POST /api/organization/sync` | 202, 404 при отсутствии организации, 409 если выполняется |
| `GET /api/organization/reviews?page=1&per_page=50` | 200 `{data: [], meta: {current_page, per_page, total, last_page}}` |
| `GET /api/parsing-runs/{id}` | 200 run; 404 для отсутствующего **или чужого** run |
| `GET /api/organization/snapshots` | 200 data/meta; 404 если организации нет |

Общие статусы: 422 validation, 401 unauthorized, 404 missing, 409 active sync, 419 CSRF/session, 429 throttle, 500 unexpected internal error.

Внешняя ошибка возникает **после HTTP 202**. Поэтому endpoint polling возвращает 200 с `status=failed|blocked`, `error_code`, `error_message` и `error_http_status=502` (500 для внутренней ошибки). Возвращать 502 из уже завершённого POST технически невозможно. Frontend учитывает состояние run. Ошибки API не содержат stack trace даже с локальным APP_DEBUG=true. Технические подробности остаются в logs.

Logs: `yandex.sync.processing`, `yandex.sync.completed`, `yandex.sync.failed`; organization_id, parsing_run_id, status, attempt, duration_ms, reviews_found, reviews_saved, error_code, тип исключения и безопасные технические детали. HTML, cookies, пароли и аргументы окружения не логируются.

# Testing

```bash
php artisan test
npm run test:parser
npm run build
# Требует работающего Nginx на APP_TEST_URL (по умолчанию symfony13.local):
npm run test:browser
vendor/bin/pint --test
npm audit
```

PHPUnit принудительно использует SQLite `:memory:`; MySQL данные не удаляются. Обычный suite не делает запросы к Яндексу. Fixtures: `valid-yandex-response.json`, `invalid-yandex-response.json`, `blocked-yandex-response.json`. Fake реализует `YandexMapsParserInterface`.

- AuthenticationTest: login/me/logout, CSRF, throttling, отсутствие регистрации, безопасный seed.
- OrganizationUrlValidationTest: разрешённые домены и отказ опасным URL.
- OrganizationSyncTest: реальный database queue worker с fake parser, 202/409, атомарное сохранение, смена карточки, сохранение старых данных, повтор job.
- ParsingRunTest: наблюдаемый progress до сохранения, retry/backoff, slots, восстановление аварийных runs.
- ReviewDeduplicationTest: 600 → 605, 10 редакций, fallback и появление ID.
- OrganizationSnapshotTest: 2 snapshots и точные counters отдельно от reviews_found.
- AuthorizationTest и PaginationTest: доступ владельца, mass assignment и страницы из БД.
- ParserResultValidationTest и YandexBlockedTest: схема, malformed/пустой результат, отсутствие fake success, CAPTCHA.
- Browser extraction tests: DOM roots, точные/сокращённые счётчики, даты, CAPTCHA и изменённая разметка, без внешних запросов.
- Browser SPA tests: реальный cookie login через Nginx; контролируемые API fixtures для polling, 50/5 pagination, XSS и мобильного blocked state. Mocked UI tests не подтверждают доступность Яндекса и не сохраняют фиктивные данные в MySQL.

Для ручной проверки внешнего parser (делает реальный запрос):

```bash
PLAYWRIGHT_BROWSERS_PATH="$PWD/storage/app/playwright" \
  node scripts/yandex-parser.mjs 'https://yandex.ru/maps/org/1098615602/'
```

При заблокированном ответе не продолжайте серию проб. Live-проверка в этом окружении обнаружила `YANDEX_BLOCKED`; успешную загрузку 600 реальных отзывов здесь подтвердить нельзя. Поведение отказа проверено без подмены результата.

# Production deployment

Nginx → PHP-FPM → Laravel/MySQL; отдельные постоянные queue workers с Node/Chromium. Пакеты Node (включая Playwright) и browser binaries должны быть доступны **worker**, а не только на этапе frontend build. Redis рекомендуется как общий cache/lock store; очередь пока database по описанной причине атомарности.

1. Установить зависимости и Chromium в release/shared directory; проверить sandbox от непривилегированного worker user.
2. Настроить HTTPS, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, точные Sanctum/SESSION domains и отдельного DB-пользователя с необходимыми правами. Заменить демонстрационные credentials.
3. `php artisan migrate --force`, `npm ci && npm run build`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`.
4. Запустить workers через Supervisor/systemd. Пример находится в `deployment/supervisor-yandex.conf`; его нужно адаптировать и установить оператором. Автоматически системные конфиги проекта не меняются.
5. Настроить scheduler (`php artisan schedule:run` раз в минуту либо постоянно `php artisan schedule:work`) для восстановления abandoned runs.
6. На deploy: `php artisan queue:restart`. Supervisor поднимет новые процессы после завершения активных jobs. Разрешить остановке процесса не меньше 390 секунд; завершать всю process group, включая Chromium.
7. Настроить log rotation, метрики queue age, duration, blocked rate, source_changed rate и coverage; алерты на остановленные workers и рост failed_jobs. `queue:failed` показывает отказы транспорта; актуальное бизнес-состояние хранится в parsing_runs.

FPM и CLI-worker должны иметь совместный доступ к `storage` и `bootstrap/cache`. Используйте общего владельца процесса/группу или ACL, без `chmod 777`. В локальной проверке обнаружен legacy log с владельцем www-data и без CLI write access; он сохранён как `storage/logs/laravel-before-queue-20260913.log`, а новый лог и каталог получили ACL для tempadmin/www-data.

Полноценный Docker Compose не добавлен: локальное окружение уже настроено. Формальный compose без проверенных browser dependencies, sandbox, volumes, permissions и корректного завершения процессов ухудшил бы воспроизводимость. Приведён проверенный локальный запуск и конкретная конфигурация Supervisor.

# Known limitations

- Яндекс блокирует текущую среду. End-to-end успешная выгрузка 600 реальных отзывов не подтверждена; CAPTCHA не обходится.
- DOM не является контрактом. Новая разметка, другой язык или формат счётчиков может потребовать обновления adapter; безопасный результат — failed, а не нули.
- `exhausted` означает прекращение выдачи новых отзывов в текущем проходе, не доказательство полного охвата. Недоступные, скрытые или удалённые источником отзывы получить нельзя.
- Нет гарантии стабильности внешнего review ID; fallback не умеет отличать редакцию от нового отзыва с изменённым текстом. По hash одинаковые отзывы без ID объединяются.
- Даты без года трактуются как последнее не будущее календарное вхождение и помечаются в raw payload; неоднозначные относительные даты остаются null. Исходная строка сохраняется.
- Retention истории и отзывов не выполняется автоматически; при длительной работе объём БД растёт.
- 32 MiB output / 2500 requests / 5 минут / 600 reviews — защитные лимиты. Их достижение может потребовать меньшего объёма или настроек после измерений.
- Сейчас нет отдельного управления филиалами, timeline UI, Redis queue/Horizon, proxy pool, уведомлений и автоматического source-change мониторинга по canary organization.
- Репозиторий содержит пример Supervisor, но не устанавливает системный сервис. Для постоянной работы нужны запущенные workers и scheduler.

# What I would improve with more time

Согласованный источник/API, canary extraction и versioned selectors по регионам; сохранённые обезличенные DOM fixtures реальных изменений; coverage alerts и circuit breaker по доменам; Redis + transactional outbox и Horizon при росте нагрузки; incremental sync и справедливая очередь филиалов; больше MySQL concurrency tests отдельной CI БД; история редакций отзывов; политика retention; проверенный Docker image с browser sandbox и ограничением ресурсов; UI списка организаций и сравнения snapshots.
