# Отчёт о реализации

Дата проверки: 13 сентября 2026. Проект: `/var/www/project13`, ветка `main`. Коммиты и push не выполнялись. Nginx и другие проекты не изменялись. Существующие данные не удалялись.

## 1. Исходное состояние

Laravel 13.16.1, PHP 8.5.6, Composer 2.8.12, Node 22.23.2, NPM 10.9.8. Sanctum, Vue 3/Router/Axios, Symfony Process, Playwright, MySQL и database queue уже установлены. Были LoginPage/SettingsPage, reviews/pagination, organization/review models и 5 миграций. Исходный suite: 11 tests / 38 assertions. Git был чистым. В локальной БД: 1 пользователь, 0 организаций, 0 отзывов, 0 jobs.

Основные недостатки: синхронный внешний запрос в HTTP endpoint, нет runs/snapshots/retry, слабая result validation, substring review selectors и удаление отзывов при смене URL.

## 2. Что изменено

Внешняя интеграция переведена в фон; добавлены runs, snapshots, строгая проверка URL/результата, сохранение прежних данных, batch upsert, общие browser locks и восстановление abandoned runs. Улучшены auth security, error responses, Vue polling, loading/error UX. Обновлены 4 npm-пакета с уязвимостями в lockfile: concurrently, shell-quote, nanoid, postcss; финальный audit чистый.

## 3. Parser

`YandexMapsParserInterface` → `PlaywrightYandexMapsParser` → Symfony Process с массивом аргументов → `scripts/yandex-parser.mjs` + `scripts/yandex-extraction.mjs`. DTO создаётся только после проверки сырого JSON. Service повторно проверяет результат и внешний ID карточки. JSONL прогресса отделён от итогового JSON. Browser sandbox включён, навигация/hosts/ресурсы/scroll/output/time ограничены.

## 4. Queue

HTTP создаёт run и database job атомарно, возвращает 202. Job работает в очереди `yandex`. Лимит 2 браузера применяется общими cache locks независимо от количества workers. Enqueue сериализован блокировкой пользователя, повторный active sync возвращает 409. Внешний запрос проходит вне транзакции сохранения. В текущем локальном окружении запущены 2 workers и scheduler в активных локальных процессах; PID-файлы находятся в storage/app/yandex-worker-local-{1,2}.pid и storage/app/scheduler-local.pid. Они работают отдельно от PHP-FPM, автоматический перезапуск после перезагрузки ОС не устанавливался; постоянный production-сервис описан в Supervisor example.

## 5. Retry

Максимум 3 фактических parser attempts по умолчанию, backoff 10/30/120 секунд (при 3 attempts используются первые два интервала). Network errors повторяются; CAPTCHA/source change/browser unavailable завершаются сразу. Ожидание slot не расходует parser attempts, общая queue deadline 6 часов. Node/Process/job/lease/retry_after согласованы: 300/315/330/360/390 секунд.

## 6. Блокировка

HTTP 401/403/429 на значимых запросах, challenge URL, CAPTCHA form и blocking headings приводят к `blocked / YANDEX_BLOCKED`. Повторы прекращаются. Обычный отзыв со словом «робот» блокировкой не считается.

## 7. Изменение структуры

Отсутствие карточки/title/exact counts/reviews container, непустого HTML или ожидаемых reviews приводит к `failed / YANDEX_SOURCE_STRUCTURE_CHANGED`. Невалидный rating не исправляется молча. При partial network/scroll failure нет успешного сохранения информации организации отдельно от отзывов.

## 8. Deduplication

Внешний ID — основной ключ, детерминированный content hash — fallback; нормализованные author/text, date, rating и organization ID. Появившийся позднее внешний ID не дублирует прежний неизменённый отзыв. Один SELECT ключей + upsert пачками по 100. Подтверждено: 600 → 605 reviews, 10 редакций обновлены. Удаления отсутствующих отзывов нет.

## 9. Snapshots

Один snapshot на успешный run, unique parsing_run_id, declared counts и changes from/to. Тест сохраняет 4.6 → 4.7, 1000 → 1050, 580 → 593. Failed sync не меняет последний успешный результат и не создаёт snapshot.

## 10. API

Сохранены и доработаны POST login/logout, GET me, GET/POST organization, POST organization/sync, GET organization/reviews. Добавлены GET `/api/parsing-runs/{id}` и GET `/api/organization/snapshots`. Все бизнес-endpoints используют auth:sanctum и owner-scoped queries. POST sync отвечает 202; асинхронные внешние ошибки доступны в run с error_http_status=502 при HTTP 200 polling.

## 11. Vue

Существующие LoginPage, SettingsPage, Router, auth, reviews и pagination использованы повторно. Добавлены OrganizationCard, SyncStatus, useOrganization, useParsing. Polling каждые 2 секунды прекращается на terminal state и unmount, восстанавливается после reload. Pagination 50/page выполняет только GET reviews; full reload и новый sync не запускаются. Отзывы экранируются Vue.

## 12. Tests

AuthenticationTest, OrganizationUrlValidationTest, OrganizationSyncTest, ParsingRunTest, ReviewDeduplicationTest, OrganizationSnapshotTest, AuthorizationTest, PaginationTest, ParserResultValidationTest, YandexBlockedTest; обновлён URL unit test. Добавлены fixtures, fake interface, реальный database queue worker integration test, проверки rollback, log failure, browser slots, debug error redaction. Обычный PHP suite не обращается к Яндексу и принудительно использует SQLite :memory:.

Дополнительно: 5 DOM-extraction tests в Chromium и 3 SPA browser tests. В browser tests настоящий Sanctum login через Nginx; success/pagination/blocked UI проверяются контролируемыми API fixtures, без фиктивных данных в MySQL.

## 13. PHPUnit

Финальный результат: **64 tests passed / 238 assertions**. Failures отсутствуют.

## 14. Frontend build

`npm run build` — PASS; Vite production assets созданы. `npm run test:parser` — 5/5 PASS; `npm run test:browser` — 3/3 PASS. `npm audit` — 0 vulnerabilities. `composer validate --no-check-publish` и Pint — PASS.

## 15. Ручные и интеграционные проверки

- `http://symfony13.local`: Nginx HTTP 200, redirect к login, admin@example.com/password, CSRF cookie, /settings, logout и 401 после выхода.
- Реальный POST организации: **202 за 154 мс**, run создан до вызова внешнего источника.
- Настоящий database worker → Chromium → Яндекс: **blocked / YANDEX_BLOCKED**, attempt=1, reviews=0, snapshot не создан. Это корректный отказ, не успешный пустой результат. В локальной БД оставлен этот реальный blocked-run для просмотра.
- MySQL persistence дополнительно проверена во внешней тестовой транзакции: 600 → 605 reviews, 10 updates, 2 snapshots. Затем вся тестовая транзакция откатилась; проверочные записи не остались в MySQL.
- Обнаружена и исправлена несовместимость прав CLI/FPM для logs: старый log сохранён под другим именем, новый наследует ACL tempadmin/www-data.
- Проверены мобильная ширина, отсутствие переполнения, буквальный вывод HTML из отзыва, остановка polling, 50/5 pagination без navigation.

Команды: аудит `git status -sb`, `git branch --show-current`, версии PHP/Composer/Node/NPM, `artisan --version/about`, структура app/resources/database; далее `npm install`, Playwright install, `php artisan migrate`, `php artisan db:seed`, `php artisan optimize:clear`, `php artisan migrate:status`, `php artisan route:list`, `php artisan test`, `npm run build`, `npm run test:parser`, `npm run test:browser`, `php artisan queue:work --queue=yandex --once --sleep=0`, `php artisan queue:failed`, `php artisan schedule:list`, Pint, composer validate, npm audit, `git status -sb`, `git diff --stat`, `git diff`, `git diff --check`.

## 16. Ограничения

Яндекс ограничивает доступ из текущего окружения: успешная загрузка ~600 реальных отзывов не подтверждена. CAPTCHA не обходится. DOM зависит от источника; доступны только заявленные домены/форматы. Exhausted означает все доступные в текущем проходе, не доказательство полноты источника; coverage явно сообщается. Без стабильного review ID редакция текста может стать новой исторической записью. Относительные даты неоднозначны. Нет timeline UI, retention, proxy pool, Redis queue/outbox и полноценного Docker окружения. Supervisor example включён, системный сервис/автозапуск не устанавливается.

Подробные установка, конфигурация, эксплуатация и сравнение extraction подходов: [README.md](README.md).

## Добавленные миграции

- `database/migrations/2026_09_13_000001_add_organization_sync_tracking.php`
- `database/migrations/2026_09_13_000002_create_parsing_runs_and_organization_snapshots.php`

Обе применены к существующей MySQL БД, статус Ran. Старые миграции сохранены.

## Созданные файлы

- `IMPLEMENTATION_REPORT.md`
- `app/Console/Commands/RecoverStaleParsingRuns.php`
- `app/DTO/ParsedYandexResult.php`
- `app/Enums/ParsingStatus.php`
- `app/Http/Controllers/Api/OrganizationSnapshotController.php`
- `app/Http/Controllers/Api/ParsingRunController.php`
- `app/Http/Resources/ParsingRunResource.php`
- `app/Jobs/Middleware/LimitYandexConcurrency.php`
- `app/Jobs/ParseYandexOrganizationJob.php`
- `app/Models/OrganizationSnapshot.php`
- `app/Models/ParsingRun.php`
- `app/Services/Yandex/OrganizationSyncScheduler.php`
- `app/Services/Yandex/PlaywrightYandexMapsParser.php`
- `app/Services/Yandex/ReviewPersister.php`
- `app/Services/Yandex/YandexMapsParserInterface.php`
- `app/Services/Yandex/YandexMapsResultValidator.php`
- `config/sanctum.php`
- `config/yandex.php`
- `database/migrations/2026_09_13_000001_add_organization_sync_tracking.php`
- `database/migrations/2026_09_13_000002_create_parsing_runs_and_organization_snapshots.php`
- `deployment/supervisor-yandex.conf`
- `resources/js/components/OrganizationCard.vue`
- `resources/js/components/SyncStatus.vue`
- `resources/js/composables/useOrganization.js`
- `resources/js/composables/useParsing.js`
- `scripts/yandex-extraction.mjs`
- `tests/Browser/app.test.mjs`
- `tests/Browser/extraction.test.mjs`
- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuthorizationTest.php`
- `tests/Feature/OrganizationSnapshotTest.php`
- `tests/Feature/OrganizationSyncTest.php`
- `tests/Feature/OrganizationUrlValidationTest.php`
- `tests/Feature/PaginationTest.php`
- `tests/Feature/ParsingRunTest.php`
- `tests/Feature/ReviewDeduplicationTest.php`
- `tests/Feature/YandexBlockedTest.php`
- `tests/Fixtures/blocked-yandex-response.json`
- `tests/Fixtures/invalid-yandex-response.json`
- `tests/Fixtures/valid-yandex-response.json`
- `tests/Support/SyncTestCase.php`
- `tests/Unit/ParserResultValidationTest.php`

## Изменённые существующие файлы

- `.env.example`
- `README.md`
- `app/DTO/ParsedOrganizationData.php`
- `app/DTO/ParsedReviewData.php`
- `app/Exceptions/YandexParserException.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/OrganizationController.php`
- `app/Http/Controllers/Api/OrganizationReviewController.php`
- `app/Http/Requests/SaveOrganizationRequest.php`
- `app/Http/Resources/OrganizationResource.php`
- `app/Models/Organization.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Yandex/OrganizationSyncService.php`
- `app/Services/Yandex/YandexMapsUrlNormalizer.php`
- `bootstrap/app.php`
- `composer.json`
- `config/queue.php`
- `database/seeders/DatabaseSeeder.php`
- `package-lock.json`
- `package.json`
- `phpunit.xml`
- `resources/css/app.css`
- `resources/js/api/http.js`
- `resources/js/app.js`
- `resources/js/composables/useAuth.js`
- `resources/js/pages/SettingsPage.vue`
- `routes/api.php`
- `routes/console.php`
- `scripts/yandex-parser.mjs`
- `tests/Unit/YandexMapsUrlNormalizerTest.php`

## Заменённые устаревшие файлы

- `app/Services/Yandex/YandexMapsParserService.php`
- `tests/Feature/AuthApiTest.php`
- `tests/Feature/OrganizationApiTest.php`

Вместо синхронного YandexMapsParserService используется PlaywrightYandexMapsParser; прежние synchronous API tests заменены expanded asynchronous suites. Дополнительно обновлён локальный `.env` (gitignored), установлены browser binaries внутри `storage/app/playwright`, сохранены диагностические результаты/скриншоты в `storage/app` и logs; секреты в Git не добавлялись.
