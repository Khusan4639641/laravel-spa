# Yandex Maps Reviews Prototype

Laravel API + Vue 3 SPA для тестового задания: пользователь вставляет ссылку на карточку организации в Яндекс.Картах, приложение сохраняет ссылку, запускает Playwright-парсер, сохраняет рейтинг/оценки/отзывы в MySQL и показывает отзывы с пагинацией.

## Стек

- PHP 8.3, Laravel 13
- Laravel Sanctum, cookie-based SPA authentication
- Vue 3 Composition API, Vue Router, Axios
- MySQL
- Node.js + Playwright/headless Chromium
- PHPUnit

## Требования

- Настроенный локальный домен: `http://symfony13.local`
- MySQL database: `laravel13`
- MySQL user/password: `root` / `root`
- PHP extensions для Laravel и MySQL
- Node.js/npm

Если базы ещё нет:

```bash
mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS laravel13 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## Первый запуск

Выполнять из корня проекта:

```bash
cd /var/www/project13
composer install
npm install
npx playwright install chromium
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

Проверить `.env`:

```env
APP_URL=http://symfony13.local
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel13
DB_USERNAME=root
DB_PASSWORD=root
SESSION_DOMAIN=symfony13.local
SANCTUM_STATEFUL_DOMAINS=symfony13.local,localhost,127.0.0.1
```

Открыть:

```text
http://symfony13.local
```

Логин:

```text
admin@example.com
password
```

## Как пользоваться

1. Открыть `http://symfony13.local`.
2. Войти seed-пользователем.
3. Перейти на `/settings`.
4. Вставить ссылку на карточку организации Яндекс.Карт:
   `https://yandex.ru/maps/org/...`
5. Нажать `Сохранить`.
6. Дождаться завершения парсинга.
7. Проверить рейтинг, количество оценок, количество отзывов и список отзывов.

Отзывы показываются по 50 на страницу. Переключение страниц читает данные из MySQL и не запускает парсер заново.

## Режим разработки

Если нужно менять frontend и видеть изменения без `npm run build`, запустить Vite:

```bash
npm run dev
```

Laravel отдаётся через уже настроенный nginx-домен `http://symfony13.local`. Nginx-конфиги для этого задания менять не нужно.

После frontend-изменений для production-сборки:

```bash
npm run build
```

## Тесты

Запустить все тесты:

```bash
php artisan test
```

Запустить только unit tests:

```bash
php artisan test --testsuite=Unit
```

Запустить только feature tests:

```bash
php artisan test --testsuite=Feature
```

Запустить конкретный тестовый класс:

```bash
php artisan test --filter=OrganizationApiTest
```

Что покрыто сейчас:

- `tests/Unit/YandexMapsUrlNormalizerTest.php`:
  - принимает ссылки `yandex.ru`, `yandex.com`, `yandex.uz` и поддомены;
  - сохраняет query-параметры;
  - извлекает внешний id организации;
  - отклоняет не-Яндекс ссылки и ссылки не на `/maps/org/`.
- `tests/Feature/AuthApiTest.php`:
  - seed-пользователь может войти;
  - `/api/me` возвращает текущего пользователя;
  - неверный пароль возвращает validation error.
- `tests/Feature/OrganizationApiTest.php`:
  - сохранение организации вызывает fake-парсер и сохраняет данные в БД;
  - отзывы возвращаются из БД с пагинацией;
  - плохая ссылка возвращает `422`;
  - ошибка парсера возвращает безопасный `502` и сохраняется в `organizations.scrape_error`.

Тестовое окружение использует sqlite in-memory и `LOG_CHANNEL=null`, поэтому тесты не зависят от MySQL и прав на `storage/logs/laravel.log`.

## Полная проверка перед сдачей

```bash
php artisan migrate:fresh --seed
php artisan test
npm run build
```

Дополнительно можно проверить login flow в браузере:

1. Открыть `http://symfony13.local`.
2. Войти `admin@example.com / password`.
3. Убедиться, что открыт `/settings`.

## API

Public:

- `POST /api/login`

Protected by `auth:sanctum`:

- `GET /api/me`
- `POST /api/logout`
- `GET /api/organization`
- `POST /api/organization`
- `POST /api/organization/sync`
- `GET /api/organization/reviews?page=1&per_page=50`

## Архитектура backend

- Controllers:
  - `AuthController`
  - `OrganizationController`
  - `OrganizationReviewController`
- Form Requests:
  - `LoginRequest`
  - `SaveOrganizationRequest`
- Services:
  - `YandexMapsUrlNormalizer`
  - `YandexMapsParserService`
  - `OrganizationSyncService`
- DTO:
  - `ParsedOrganizationData`
  - `ParsedReviewData`

Контроллеры только валидируют запрос, вызывают сервисы и возвращают JSON. Парсинг и синхронизация не находятся в контроллерах.

## Подход к парсингу

У Яндекс.Карт нет официального API для этой задачи, поэтому используется headless browser:

- Laravel вызывает `scripts/yandex-parser.mjs` через Symfony Process.
- Node.js-скрипт открывает карточку организации в Chromium.
- Скрипт пытается открыть блок отзывов.
- Затем он раскрывает видимые тексты отзывов и прокручивает контейнер отзывов, пока Яндекс подгружает новые отзывы.
- Парсер останавливается после нескольких скроллов без новых отзывов, по timeout или по лимиту примерно до 600 отзывов.
- Результат возвращается JSON в stdout.
- Ошибки возвращаются в stderr и ненулевой exit code.

Формат stdout:

```json
{
  "organization": {
    "title": "Название организации",
    "rating": 4.7,
    "ratings_count": 1234,
    "reviews_count": 612,
    "external_id": "optional-id",
    "meta": {}
  },
  "reviews": [
    {
      "external_id": "optional-review-id",
      "author": "Имя автора",
      "date": "2026-06-01",
      "text": "Текст отзыва",
      "rating": 5,
      "raw": {}
    }
  ]
}
```

Laravel сохраняет метрики организации и upsert-ит отзывы:

- по `external_id`, если он есть;
- по `content_hash`, если `external_id` отсутствует.

Пагинация работает только по сохранённым отзывам в MySQL.

## Troubleshooting

Если парсер пишет, что Chromium не установлен:

```bash
npx playwright install chromium
```

Если после изменения Vue не обновляется интерфейс:

```bash
npm run build
```

Если нужно полностью пересоздать базу и seed-пользователя:

```bash
php artisan migrate:fresh --seed
```

Если Яндекс показывает CAPTCHA или блокирует headless browser, приложение вернёт:

```text
Yandex blocked automated access or captcha required
```

CAPTCHA не обходится специально.

## Ограничения

- Яндекс может изменить HTML-разметку, тогда селекторы парсера нужно обновить.
- Яндекс может блокировать автоматический доступ.
- Парсинг сейчас синхронный и выполняется во время HTTP-запроса.
- Production-версия должна использовать queue, retry policy, мониторинг, лимиты частоты запросов и proxy/rate-limit подходы только там, где это юридически допустимо.

## Future Tests / что добавить позже

- Parser fixture tests на сохранённых HTML-снапшотах разных локалей Яндекс.Карт.
- Playwright E2E test для полного пользовательского сценария login → settings → save URL → reviews.
- Queue/job tests, если синхронизация будет вынесена из HTTP-запроса.
- Contract tests для JSON-формата parser stdout.
- Regression tests для дедупликации отзывов по `external_id` и `content_hash`.
- Browser visual checks для `/login` и `/settings` на desktop/mobile.
