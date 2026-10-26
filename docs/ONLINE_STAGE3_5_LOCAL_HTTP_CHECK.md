# Этап 3.5. Локальная HTTP-проверка API перед размещением на REG.RU

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Тип:** локальная проверка. Код API, SQL-миграция, фронтенд и конфигурация не менялись.
**Обращений к REG.RU, `crm.devisd.online`, рабочей MySQL, FTP, phpMyAdmin:** не было. Commit, push, деплой: не выполнялись.

Связанные документы: `ONLINE_STAGE3_4_DEPLOY_AUDIT.md` (риски, план серверной проверки).

---

## 1. Итог

- Через настоящий HTTP-сервер (встроенный сервер PHP 8.3.36 на `127.0.0.1`) выполнено **19 сценариев — 19 PASS**: статусы, тела, заголовки, `HEAD` без тела, `Allow`, `X-Request-Id`, отсутствие утечек, защита путей M1/M4.
- Дефектов в коде API **не найдено**. Есть одно наблюдение окружения (`X-Powered-By`, раздел 6) — его нужно проверить на хостинге.
- Существующие PHP-тесты: **27 / 0 / 0**, `php -l` — 16 / 16.
- Встроенный сервер **не является Apache**: `.htaccess`, `mod_rewrite`, FastCGI, `open_basedir` и редиректы им не проверяются (раздел 7). Готовность к production этим этапом не подтверждается.

---

## 2. Окружение и способ запуска

| Параметр | Значение |
|----------|----------|
| PHP | 8.3.36 NTS x64 (Windows), вне репозитория; `pdo_mysql` загружен |
| HTTP-клиент | `curl.exe` 8.21.0 (Windows) для GET/POST; для `HEAD` — сырой HTTP/1.1-запрос через сокет, чтобы надёжно измерить длину тела |
| Сервер | `php -S 127.0.0.1:<свободный порт> -t <document root> <router>` — только loopback |
| Router | копия реального `api/public/index.php` (заменяет rewrite из `.htaccess` для `/api/*`) |
| Раскладка | временный каталог вне репозитория, как на REG.RU: `<tmp-home>/www/crm.devisd.online/api/{index.php,.htaccess}` и `<tmp-home>/devis-crm/api/src/` (реальные исходники), `<tmp-home>/devis-crm/config/`, `logs/` создаётся API |
| Конфиг | временный тестовый `local.php` с заведомо фиктивными значениями (хост `127.0.0.1`, порт 1, тестовые маркеры вместо имени пользователя и пароля); удалён сразу после сценариев |
| Скрипт | `%LOCALAPPDATA%\DevisTools\scripts\07-http-check.ps1` (вне репозитория) |
| Уборка | в `finally`: серверы остановлены (0 процессов), временный каталог удалён |

Для случаев «пустой» и «несуществующий» `DOCUMENT_ROOT` встроенный сервер не подходит напрямую: он всегда выставляет `DOCUMENT_ROOT` из существующего каталога `-t`. Поэтому использованы тестовые **обёртки-router** вне репозитория, которые меняют только `$_SERVER['DOCUMENT_ROOT']` и подключают тот же `index.php`. Код API не менялся.

---

## 3. HTTP-сценарии (раскладка REG.RU)

Во всех ответах приложения: `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `X-Request-Id` (16 hex). Проверка утечек по заголовкам и телу: тестовые пароль и пользователь, пути временного каталога, любые `X:\…`, `mysql:`, `SQLSTATE`, `PDOException`, `ErrorException`, `Stack trace`, `local.php`, `.php on line` — **не найдено ни в одном ответе**.

| ID | Запрос | Условие | Статус | Тело | Контракт |
|----|--------|---------|--------|------|----------|
| A1 | `GET /api/health` | нет конфига | 503 | `configuration_error` | соответствует |
| A2 | `GET /api/health/` | нет конфига | 503 | `configuration_error` | соответствует (слэш нормализуется) |
| A3 | `GET /api/index.php/health` | нет конфига | 503 | `configuration_error` | соответствует (резервный путь без rewrite) |
| A4 | `HEAD /api/health` | нет конфига | 503 | пусто (0 байт) | соответствует |
| A5 | `POST /api/health` | — | 405 | `method_not_allowed`, `Allow: GET, HEAD` | соответствует |
| A6 | `GET /api/unknown` | — | 404 | `not_found` | соответствует |
| A7 | `GET /api` | — | 404 | `not_found` | соответствует коду; **на Apache ожидается 301 → `/api/`** (mod_dir) |
| A8 | `GET /api/index.php` | — | 404 | `not_found` | соответствует (путь `/` не маршрут) |
| A9 | `GET /api/%FF` | невалидный UTF-8 | 400 | `bad_request` | соответствует |
| A10 | `HEAD /api/unknown` | — | 404 | пусто | соответствует |
| A11 | `GET /api/health` | конфиг с `__CHANGE_ME__` | 503 | `configuration_error` | соответствует |
| A12 | `GET /api/health` | конфиг с синтаксической ошибкой PHP | 503 | `configuration_error` | соответствует |
| A13 | `GET /api/health` | БД недоступна (`127.0.0.1:1`) | 503 | `db_unavailable` | соответствует |
| A14 | `HEAD /api/health` | БД недоступна | 503 | пусто | соответствует |

Тела (дословно):

```text
configuration_error: {"ok":false,"error":{"code":"configuration_error","message":"Service is not configured"}}
db_unavailable:      {"ok":false,"error":{"code":"db_unavailable","message":"Database is unavailable"}}
not_found:           {"ok":false,"error":{"code":"not_found","message":"Resource not found"}}
method_not_allowed:  {"ok":false,"error":{"code":"method_not_allowed","message":"Method not allowed"}}
bad_request:         {"ok":false,"error":{"code":"bad_request","message":"Malformed request"}}
```

Заголовки ответа A1 (номер порта и дата опущены):

```text
HTTP/1.1 503 Service Unavailable
Host: 127.0.0.1:<port>
Connection: close
X-Powered-By: PHP/8.3.36
X-Request-Id: <16 hex>
Content-Type: application/json; charset=utf-8
Cache-Control: no-store
X-Content-Type-Options: nosniff
```

`Host` в ответе и `Connection: close` добавляет встроенный сервер, а не API.

### Приватный лог `api.log`

- Создан в `<tmp-home>/devis-crm/logs/` (вне document root).
- Содержит записи с тем же `X-Request-Id`, что вернулся клиенту (A1 → `configuration_error`, A13 → `db_unavailable`) — корреляция работает.
- Тестовый пароль, тестовый пользователь, DSN (`mysql:`), текст PDO-исключения — **не найдены**. Есть `SQLSTATE` и код драйвера — это задумано (`Db::describe`), реквизитов не содержит.
- Содержимое конфига с синтаксической ошибкой (A12) в лог **не попало**.
- Пути к конфигу в приватном логе есть — по замыслу (лог вне webroot); в HTTP-ответах их нет.

### `200 OK`

Не проверялся: для ответа 200 нужна работающая MySQL, а подключаться к реальной базе на этом этапе нельзя. Успешный путь покрыт только CLI-тестом с заглушкой БД.

---

## 4. Защита путей M1 и M4 через HTTP

| ID | Раскладка | `DOCUMENT_ROOT` | Статус | Тело | `X-Request-Id` | Причина в `error_log` сервера |
|----|-----------|-----------------|--------|------|----------------|-------------------------------|
| A6 | безопасная: код и родитель вне document root | `<tmp-home>/www/crm.devisd.online` | 404 | `not_found` | есть | — |
| M1 | `api/src` в `<docroot>/src/` (`apiRoot` = document root) | `<tmp-home>/www/crm.devisd.online` | 503 | `configuration_error` | нет | `private API directory is inside the document root` |
| P1 | родитель `apiRoot` внутри document root | `<tmp-home>` | 503 | `configuration_error` | нет | `private API directory is inside the document root` |
| P2 | похожий префикс, не вложен | `<tmp-home>/devis` (рядом с `<tmp-home>/devis-crm`) | 404 | `not_found` | есть | — |
| M4a | — | пустая строка (обёртка-router) | 503 | `configuration_error` | нет | `document root is unknown or cannot be resolved` |
| M4b | — | несуществующий путь (обёртка-router) | 503 | `configuration_error` | нет | `document root is unknown or cannot be resolved` |

Небезопасные конфигурации отклоняются **до запуска приложения** (нет `X-Request-Id`), с общим ответом; причины в `error_log` не содержат путей. Предупреждений и ошибок PHP в логах сервера нет.

---

## 5. Существующие проверки

| Проверка | Результат |
|----------|-----------|
| `php -l` (16 файлов) | 16 / 16 без ошибок |
| `php api/tests/run.php` | 27 пройдено, 0 провалено, 0 пропущено, exit 0 |

---

## 6. Выявленные дефекты и наблюдения

| # | Уровень | Наблюдение | Рекомендация |
|---|---------|-----------|--------------|
| — | — | Дефектов в коде API не выявлено. | — |
| O1 | low (окружение) | Ответы содержат `X-Powered-By: PHP/8.3.36`. Заголовок добавляет PHP при `expose_php = On` (так в локальном `php.ini`, скопированном из `php.ini-development`); код API его не ставит. Раскрывает точную версию PHP. | Проверить на REG.RU. Предпочтительно `expose_php = Off` в настройках PHP сайта. Если панель не позволяет — отдельным согласованным изменением удалять заголовок в коде (`header_remove('X-Powered-By')`); на этом этапе не применялось. |
| O2 | info | Без `mod_dir` встроенный сервер отвечает на `/api` JSON 404; Apache, скорее всего, вернёт 301 на `/api/`. | Проверить на хостинге (схема `https` в `Location`). |
| O3 | info | `SQLSTATE` и код драйвера пишутся в приватный `api.log`. | Ожидаемое поведение для диагностики; реквизитов нет. |

---

## 7. Ограничения встроенного сервера и ожидания для Apache на REG.RU

Встроенный сервер PHP **не выполняет `.htaccess`**: роль rewrite выполнял router-скрипт (тот же `index.php`), который получает **все** запросы. Поэтому поведение Apache ниже — ожидание, а не результат.

| Запрос | Подтверждено локально | Ожидается на REG.RU (Apache + `.htaccess`) | Что проверить на REG.RU |
|--------|-----------------------|-------------------------------------------|-------------------------|
| `GET /api/health` | 503 без конфига / 503 при недоступной БД; JSON-заголовки | rewrite → `index.php`; 503 без конфига, 200 с рабочей БД | `mod_rewrite`, `AllowOverride`, `FollowSymLinks`/`SymLinksIfOwnerMatch`, `REQUEST_URI` через FastCGI |
| `GET /api/unknown` | 404 JSON | 404 JSON через rewrite | то же |
| `POST /api/health` | 405 + `Allow: GET, HEAD` | так же | что прокси/FastCGI не меняют `Allow` |
| `HEAD /api/health` | статус как у GET, тело 0 байт | так же; Apache может добавить `Content-Length: 0` | реальные заголовки |
| `/api` без слэша | 404 JSON (без mod_dir) | вероятно 301 → `/api/` | схема и хост в `Location` за nginx-прокси |
| Случайные файлы внутри `/api/` | не проверяемо (router получает всё) | `.htaccess` переписывает всё, кроме `index.php`, на `index.php` → 404 JSON; `.htaccess` обычно 403 от сервера | что файлы не отдаются; без `mod_rewrite` — отдаются напрямую (риск M2) |
| Разрешение путей | `realpath()` и проверки M1/M4 — на Windows | Linux, `open_basedir` | что `DOCUMENT_ROOT` и `<home>/devis-crm` разрешаются (иначе 503) |
| Заголовки ответа | как в разделе 3 | плюс заголовки Apache/nginx; `X-Powered-By` зависит от `expose_php` | реальный набор заголовков |

### Что по-прежнему требует REG.RU

1. Обработка `.htaccess`, `mod_rewrite`, `AllowOverride`, `FollowSymLinks`, FastCGI, nginx-прокси.
2. `DOCUMENT_ROOT`, `open_basedir`, `disable_functions`, `expose_php`, расположение журнала ошибок PHP.
3. Разрешение `realpath()` для document root и `<home>/devis-crm` (иначе 503 после 3.4.1).
4. Права записи в `<home>/devis-crm/logs/`.
5. Реальные HTTP-статусы, заголовки, редирект `/api` → `/api/`.
6. Подключение к MySQL, ответ 200, импорт `001_init.sql`.

Порядок проверки — раздел 8 `ONLINE_STAGE3_4_DEPLOY_AUDIT.md`.

---

## 8. Подтверждения

- Обращений к REG.RU, ISPmanager, FTP, phpMyAdmin, `crm.devisd.online`, рабочей MySQL — не было. Единственное «подключение к БД» — попытка на закрытый порт `127.0.0.1:1` (отказ соединения).
- Сервер слушал только `127.0.0.1`.
- Реальные секреты не создавались; тестовый `local.php` с фиктивными значениями удалён вместе с временным каталогом.
- Код API, `api/migrations/001_init.sql`, фронтенд и конфигурация репозитория не менялись.
- Commit, push, деплой не выполнялись.
