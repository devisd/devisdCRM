# Этап 3.1. Обследование и проектирование PHP API (без реализации)

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Контрольная точка:** `b2d1913` — `chore: prepare CRM for static hosting`
**Production URL (цель):** `https://crm.devisd.online`
**Реализация PHP/SQL/CI/деплой на этом этапе:** не создавалась.

---

## 1. Проверенное состояние Git

| Проверка | Результат |
|----------|-----------|
| Ветка | `feature/online-migration` |
| `git log -1` | `b2d1913 chore: prepare CRM for static hosting` |
| `git status --short` | `?? docs/ONLINE_STAGE2_2_2_COMMIT.md` (отчёт после коммита; не трогался) |
| Разрушающие команды | не выполнялись |

Незакоммиченное: только untracked `docs/ONLINE_STAGE2_2_2_COMMIT.md` и (после этого этапа) данный файл `docs/ONLINE_STAGE3_1_DESIGN.md`. Код приложения не менялся.

---

## 2. Текущая архитектура (факт из репозитория)

### 2.1. Frontend

| Элемент | Факт |
|---------|------|
| Стек | Next.js **16.4.2**, React 19.3, TypeScript, SCSS, Zod, Dexie |
| Сборка | `output: 'export'`, `trailingSlash: true` (`next.config.ts`) |
| Сервер Node в production | не используется |
| API Routes Next | отсутствуют |
| Данные сейчас | **IndexedDB** (`devis-studio`, Dexie v1) |

Маршруты detail/edit — query (`/clients/detail/?id=`, `/orders/edit/?id=` и т.д.), см. ADR-001.

### 2.2. Модель данных (Dexie / Zod)

Сущности: `clients`, `orders`, `orderItems`, `payments`, `appMeta` (`nextOrderNumber`, `schemaVersion`).

Деньги — **целые копейки** (`*Minor`). Статусы заказа: `new` | `in_progress` | `waiting` | `completed` | `cancelled`. Методы оплаты: `cash` | `bank_transfer` | `other`. Статус оплаты (вычисляемый): `unpaid` | `partially_paid` | `paid` | `not_required`.

JSON-бэкап v1: `app: "Devis Studio Work Tracker"`, `backupVersion: 1`, поля `exportedAt` + `data.{clients,orders,orderItems,payments}` с проверкой связей и арифметики (`src/lib/services/backup.ts`).

### 2.3. Операции, которые в будущем должен обслуживать PHP API

Соответствие текущим repositories/services (не выдумано):

| Область | Операции |
|---------|----------|
| Клиенты | list / get / create / update / archive / unarchive / delete-if-no-orders |
| Заказы | list / get / by-client / items / payments / create(+items, номер) / update(+items) / archive / status / addPayment (без переплаты, txn) |
| Обзор | KPI / списки «последние» / «сроки» |
| Бэкап | export JSON v1 / import replace-all (txn + validate) / clear с подтверждением |
| Служебное | healthcheck; позже — auth owner-only |

Правила денег (`money.ts`, `paymentStatus.ts`) на сервере нужно **зеркалировать**, не ослаблять.

### 2.4. Что пока остаётся на IndexedDB

До отдельного этапа cutover (в плане миграции — поздние этапы):

- весь UI CRUD через Dexie repositories;
- локальный экспорт/импорт/очистка в настройках;
- отсутствие обязательной сети для работы с данными.

Переходный вариант (из аудита этапа 0, ещё не реализован): адаптер `local | remote` без удаления Dexie.

`ONLINE_MIGRATION_PLAN.md` местами описывает более ранний вариант с PostgreSQL/Auth.js; для REG.RU целевой стек зафиксирован документами online-аудита и этим этапом: **PHP 8.3 + MySQL 8**.

---

## 3. Известные ограничения хостинга (только подтверждённое документами / ТЗ этапа)

| Утверждение | Статус |
|-------------|--------|
| Целевой домен `https://crm.devisd.online` | цель в docs |
| Static frontend (Next export) | реализовано в репо |
| API на том же origin под `/api/` | целевая схема в docs |
| PHP **8.3**, MySQL **8**, ISPmanager, **без SSH** | задано ТЗ этапа 3.1 / docs |
| Деплой FTP/SFTP, раздельно frontend и API | план в docs; **не настроен** |
| Конфиг БД вне webroot | **требование**; факт пути на сервере **не проверен** |
| База MySQL и пользователь уже созданы | **не подтверждено** репозиторием |
| Фактический document root / права / phpMyAdmin | **не проверены** из этой среды |
| PHP/MySQL на сервере уже работают с CRM | **нет** — API не поднимался |

Секреты (пароли БД, FTP) в репозиторий не запрашиваются и не должны попадать.

`.gitignore` уже игнорирует `.env*`, `/out/`, `/.next/`, `node_modules`. Для будущего: добавить игнор `config/local.php` / `*.local.php` (на этапе реализации).

---

## 4. Предлагаемая структура PHP API (минимальная)

Файлы **не создаются** на 3.1; это целевой каркас для 3.2+:

```text
devisdCRM/
  api/
    public/
      index.php              # единственная публичная точка (/api/ → сюда)
      .htaccess              # rewrite на index.php (если Apache позволяет; факт — на хостинге)
    src/
      bootstrap.php
      Config.php             # читает конфиг вне webroot
      Db.php                 # PDO singleton, ATTR_ERRMODE, prepared only
      Http/
        Request.php
        JsonResponse.php     # единый JSON envelope
        Router.php
      Controllers/
        HealthController.php
      Logging/
        Logger.php           # файл/syslog вне public; без секретов в сообщениях
    migrations/
      001_init.sql
    bin/                     # не в public
      migrate.php            # опционально; только с секретом / удалить после прогона
  config/
    local.php.example        # в git
    local.php                # НЕ в git; на сервере вне public_html, если возможно
  out/                       # static frontend artifact (gitignore)
  src/                       # Next.js app (как сейчас)
```

### 4.1. Маршрутизация и JSON

- Все запросы `/api/*` → `api/public/index.php`.
- Router: method + path → controller action.
- Ответ:

```json
{ "ok": true, "data": { } }
```

```json
{ "ok": false, "error": { "code": "…", "message": "…" } }
```

- HTTP-коды: 200/201/400/401/403/404/409/500 (без SQL text / stack / DSN в теле).
- Логи: код ошибки + request id; **не** пароли, не полный DSN с credentials.

### 4.2. PDO и конфигурация

- `PDO` + prepared statements только.
- DSN/user/password из файла вне webroot (или недоступного по HTTP каталога).
- Пример только в `local.php.example` без реальных значений.

### 4.3. Независимое размещение

| Артефакт | Куда (цель) | Как не затереть |
|----------|-------------|-----------------|
| Содержимое `out/` | document root сайта | deploy frontend **не** трогает `api/` |
| `api/public/` | URL `/api/` | отдельный FTP path / exclude |
| `config/local.php` | вне public | ручная установка один раз |

Точные пути — после ответов из ISPmanager (раздел 6).

### 4.4. Авторизация (только проектирование, не этап 3.1/3.2 скелета)

Отдельный последующий этап:

- один owner, без публичной регистрации;
- cookie-сессия (`HttpOnly`, `Secure`, `SameSite`);
- CSRF / double-submit на мутации;
- `clear` / import / migrate — только authenticated + доп. подтверждение;
- migrate-скрипт не оставлять публично доступным.

На скелете 3.2: **не** открывать CRUD и **не** открывать произвольный SQL.

---

## 5. План первой SQL-миграции (`001_init.sql`)

Цель: схема под текущую модель + задел под owner. Данные CRM пока пустые (переноса реальных данных нет).

Предлагаемые таблицы (черновик имён ≈ модели):

| Таблица | Назначение |
|--------|------------|
| `schema_migrations` | версия применённых SQL-файлов |
| `users` | один owner: email, password_hash, created_at (заполнение — этап auth) |
| `sessions` | опционально на auth-этапе; можно отложить |
| `clients` | поля клиента + archive + timestamps |
| `orders` | number UNIQUE, client_id FK, money minors BIGINT, status enum/string |
| `order_items` | FK order, qty DECIMAL/DOUBLE осторожно или DECIMAL, unit_price/line_total BIGINT |
| `payments` | FK order, amount BIGINT, method, dates |
| `app_meta` | key/value (`next_order_number`, …) |

Индексы — по аналогии с Dexie stores (clientId, status, dates, archive).
Charset: `utf8mb4`.
Деньги: `BIGINT` копейки, не FLOAT.

**Запуск без SSH (варианты, выбор после ISPmanager):**

1. Импорт `.sql` через phpMyAdmin / раздел БД в панели.
2. Одноразовый `bin/migrate.php` с одноразовым секретом в конфиге → применить pending → **удалить файл** и отозвать секрет.

Не предусматривать endpoint «выполнить произвольный SQL».

---

## 6. Требования к healthcheck

Минимальный безопасный эндпоинт, например `GET /api/health`:

| Требование | Деталь |
|------------|--------|
| Без auth на первом скелете | допустимо только если ответ не раскрывает инфраструктуру |
| Проверка PDO | `SELECT 1` |
| Успех | `{ "ok": true, "data": { "status": "ok", "db": true } }` |
| Сбой БД | HTTP 503, `{ "ok": false, "error": { "code": "db_unavailable", "message": "…" } }` без SQL/DSN |
| Не возвращать | версии пакетов с путями сервера, список таблиц, секреты |
| Не мутировать | только чтение |

Опционально позже: флаг «миграции применены» через `schema_migrations` (осторожно с утечкой состава схемы).

---

## 7. Риски и нерешённые вопросы

1. Document root и URL `/api/` на REG.RU не проверены — возможна другая раскладка каталогов.
2. Хранение конфига вне webroot может быть недоступно на тарифе — тогда нужен каталог вне HTTP alias + запрет по правилам веб-сервера.
3. Deploy frontend может затереть API без жёсткого exclude.
4. `.htaccess` rewrite для front controller может быть ограничен панелью.
5. Дублирование money-правил PHP ↔ TS — риск расхождения без общих тестов/фикстур.
6. `ONLINE_MIGRATION_PLAN.md` частично устарел (Postgres) относительно REG.RU PHP-пути.
7. Auth ещё не спроектирован детально (сессии vs tokens) — блокирует CRUD в сети.

---

## 8. Вопросы для проверки в ISPmanager / документации REG.RU

Интерфейс ISPmanager зависит от версии; ниже — **что** выяснить и **где искать по смыслу**, без выдуманных точных названий пунктов меню.

| # | Вопрос | Где проверить |
|---|--------|----------------|
| 1 | Фактический document root сайта `crm.devisd.online` | Карточка сайта / домена / WWW-домена; файловый менеджер — каталог, куда указывает сайт |
| 2 | Можно ли отдать PHP из подкаталога того же домена как `/api/` | Структура каталогов сайта + настройки домена / обработчики PHP; при сомнении — документация REG.RU по размещению PHP-приложений в подпапке |
| 3 | Можно ли хранить PHP-конфиг **вне** публичной директории сайта | Файловый менеджер: каталоги выше/рядом с document root; документация хостинга по «файлам вне public» / правам |
| 4 | FTP или SFTP; раздельная загрузка frontend и API | Раздел учётных записей FTP/SFTP; корневые каталоги учёток; можно ли две цели деплоя |
| 5 | Запуск SQL без SSH | Раздел баз MySQL; наличие phpMyAdmin или встроенного SQL-интерфейса в панели |
| 6 | Как гарантированно закрыть временный migrate-скрипт | Права на удаление через файловый менеджер/FTP; запрет исполнения в каталоге после удаления; не оставлять URL в индексе |

Дополнительно подтвердить в панели: версия PHP **8.3** для сайта и наличие MySQL **8** (создание БД/пользователя — факт панели, не репозитория).

---

## 9. Критерии готовности к этапу 3.2

Этап 3.2 (скелет API + PDO + `001_init` + healthcheck) можно начинать, когда:

1. Этот документ согласован (структура каталогов и JSON-контракт приняты).
2. Есть рабочие ответы хотя бы на вопросы 1–2 и 4–5 раздела 8 (куда класть static и `/api/`, чем заливать, чем гонять SQL).
3. Известно, где будет лежать `local.php` (вне webroot или согласованный fallback).
4. Понятно, что 3.2 **не** включает auth/CRUD/backup — только каркас, миграция, healthcheck, пример конфига.
5. Untracked отчёты (`ONLINE_STAGE2_2_2_COMMIT.md`, этот файл) либо закоммичены отдельно, либо явно отложены — без смешивания с кодом API без запроса.

---

## 10. Границы этапа 3.1

Не создавались: PHP-файлы, SQL, `.htaccess`, GitHub Actions, деплой, изменения `src/` приложения, коммиты.

Следующий шаг (**3.2**) — только после отдельного подтверждения.
