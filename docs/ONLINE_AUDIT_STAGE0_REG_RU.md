# Этап 0. Аудит CRM Devis Studio → online (REG.RU)

**Дата аудита:** 2026-10-25
**Ветка:** `feature/online-migration`
**Production URL (цель):** `https://crm.devisd.online`
**Целевой стек:** Next.js Static Export + PHP API + MySQL 8 + GitHub Actions (FTP/SFTP, без SSH)

Код приложения на этом этапе **не изменялся**. Реализация API **не создавалась**.

---

## 1. Фактическое состояние проекта

### 1.1. Стек и версии (из `package.json`)

| Пакет | Версия |
|-------|--------|
| `next` | 16.4.2 |
| `react` / `react-dom` | 19.3.0 |
| `typescript` | ^5 |
| `dexie` | ^4.4.6 |
| `zod` | ^4.6.7 |
| `date-fns` | ^4.4.0 |
| `lucide-react` | ^1.61.0 |
| `sass` | ^1.106.1 |
| `vitest` | ^5.0.5 |
| `eslint` / `eslint-config-next` | ^9 / 16.4.2 |

Режим: **локальный MVP**, данные только в **IndexedDB** через Dexie. Серверного backend в репозитории нет.

### 1.2. Структура (ключевое)

```text
src/
  app/                 # App Router: /, /clients, /orders, /settings + динамические [id]
  components/          # UI, clients, orders, overview, settings, layout
  lib/
    db/                # Dexie schema (database.ts, errors.ts)
    repositories/      # clients.ts, orders.ts  ← прямое обращение к IndexedDB
    services/          # money, paymentStatus, backup, overview, stats, lists
    validation/        # Zod entity + form schemas
    format/            # currency, dates
    hooks/             # useDbReady, useAsyncResource, useUnsavedWarning
  types/               # re-export из Zod
```

Документы:

| Файл | Статус на момент аудита |
|------|-------------------------|
| `README.md` | Есть |
| `ONLINE_MIGRATION_PLAN.md` | Есть (общий план; ранее ориентировался на Next+Postgres) |
| `SPEC.md` | **В рабочей копии отсутствует** (`ls` → No such file). README всё ещё ссылается на него |
| `.github/` | **Отсутствует** — GitHub Actions workflow не найдены |

### 1.3. Конфигурация сборки (`next.config.ts`)

```ts
cacheComponents: true
partialPrefetching: true
```

- `output: 'export'` **не задан**
- `basePath` / `assetPrefix` **не заданы**
- API Routes / Route Handlers (`src/app/api/**`) **не найдены**
- `generateStaticParams` **не найден**
- `export const dynamic` / `runtime` **не найдены**
- `next/headers`, `'use server'` **не найдены**

Текущий `npm run build` собирает приложение для Node (`next start`), не как чистый static export.

### 1.4. IndexedDB / Dexie

**Схема** (`src/lib/db/database.ts`):

| Таблица | Назначение |
|--------|------------|
| `clients` | Клиенты |
| `orders` | Заказы |
| `orderItems` | Позиции |
| `payments` | Платежи |
| `appMeta` | `nextOrderNumber`, `schemaVersion` |

`getDb()` бросает ошибку вне браузера / без IndexedDB.

**Прямой доступ к БД (не через UI-компоненты):**

- `src/lib/repositories/clients.ts`
- `src/lib/repositories/orders.ts`
- `src/lib/services/backupOps.ts` (`exportBackup`, `replaceDatabaseWithBackup`, `clearAllData`, …)
- `src/lib/services/overview.ts` (`loadOverviewStats` → `getDb().payments`)
- `src/lib/services/clientSummaries.ts` (`listClientSummaries` → `getDb().payments`)
- `src/lib/services/orderList.ts` (`listOrderRows` → репозитории)

UI-компоненты **не** импортируют Dexie напрямую; они вызывают repositories/services + хуки `useDbReady` / `useAsyncResource`.

### 1.5. Репозитории (контракт CRUD)

**Клиенты:** `listClients`, `getClientById`, `createClient`, `updateClient`, `archiveClient`, `unarchiveClient`, `deleteClientIfNoOrders`.

**Заказы:** `listOrders`, `getOrderById`, `getOrdersByClientId`, `getOrderItems`, `getOrderPayments`, `createOrder` (транзакция + номер), `updateOrder` (позиции пересоздаются), `archiveOrder`, `unarchiveOrder`, `updateOrderStatus`, `addPayment` (проверка остатка + транзакция).

### 1.6. Деньги и статусы

Чистая логика в `src/lib/services/money.ts`, `paymentStatus.ts`, `stats.ts`:

- суммы в **целых копейках**
- позиция = qty × цена; итог = сумма − скидка
- остаток = max(итог − оплаты, 0)
- статус оплаты: `unpaid` | `partially_paid` | `paid` | `not_required` (при итоге 0)
- запрет переплаты; отменённые заказы исключаются из дохода

### 1.7. Zod и бэкап

- Сущности: `src/lib/validation/schemas.ts` (+ `backupFileSchema`, `backupVersion: 1`, app `"Devis Studio Work Tracker"`)
- Формы: `clientForm.ts`, `orderForm.ts`, `paymentForm.ts`
- Валидация/экспорт/импорт: `backup.ts`, `backupOps.ts` — формат JSON **совместим** как контракт миграции на сервер

### 1.8. Маршруты App Router

| Маршрут | Тип по `next build` (факт) |
|---------|----------------------------|
| `/`, `/clients`, `/clients/new`, `/orders`, `/orders/new`, `/settings`, `/_not-found` | ○ Static |
| `/clients/[id]`, `/clients/[id]/edit`, `/orders/[id]`, `/orders/[id]/edit` | ◐ Partial Prerender |

Клиентские страницы данных обёрнуты в `'use client'` компоненты.
`OrderFormPage` использует `useSearchParams` (в Suspense).
`SidebarNav` использует `usePathname`.

### 1.9. Результаты команд (запущено 2026-10-25)

| Команда | Результат |
|---------|-----------|
| `npm run typecheck` | **OK** (`tsc --noEmit`) |
| `npm run lint` | **OK** (`eslint`, без ошибок в выводе) |
| `npm test` | **FAIL** — 9/9 test files упали при загрузке: `TypeError: Cannot read properties of undefined (reading 'config')` на `describe(...)`. Тесты ранее (в истории проекта) проходили; **текущий прогон неуспешен**. Причина не чинилась на этапе 0 |
| `npm run build` | **OK** (Next.js 16.4.2 Turbopack, 13 маршрутов) |

Static export (`output: 'export'`) **в этом прогоне не включался и не проверялся**.

CI/CD: workflow **не найдены**, прогон Actions **не выполнялся**.

---

## 2. Что переиспользовать без переписывания

| Модуль | Зачем |
|--------|--------|
| `src/components/**` | UI, формы, списки, обзор, настройки |
| `src/lib/services/money.ts` (+ тесты после починки Vitest) | Единый источник правды по деньгам на клиенте; дублировать правила в PHP |
| `src/lib/services/paymentStatus.ts`, `stats.ts`, `labels.ts`, `orderList.ts` (pure), `clientSummaries` (pure builders), `overview` (`buildOverviewStats`) | Клиентская агрегация после ответа API |
| `src/lib/validation/**` | Контракты полей; эталон для серверной валидации |
| `src/lib/format/**` | Отображение рублей/дат |
| `src/lib/services/backup.ts` | Формат и проверка JSON-бэкапа |
| `src/types` | Типы сущностей |
| Сигнатуры `repositories/*` | Целевой интерфейс HTTP-клиента (те же имена функций) |

**Не переносить на сервер как есть:** реализацию Dexie в `lib/db/*` и тело `repositories` / `backupOps` с `getDb()`.

На этапе миграции Dexie **сохраняем** в репозитории (feature-flag / адаптер), пока online не станет основным.

---

## 3. Проблемы и несовместимости

### 3.1. Критично для Static Export

1. **Динамические сегменты `[id]`** без `generateStaticParams` и с Partial Prerender.
   При `output: 'export'` Next.js требует заранее известные пути **или** смену модели маршрутизации.
   UUID клиентов/заказов на билде неизвестны → нужен один из вариантов:
   - **A (рекомендуется):** статические пути + query (`/clients/detail/?id=…`) или hash;
   - **B:** SPA-оболочка + rewrite на REG.RU (`FallbackResource` / `.htaccess`) на `index.html` / `clients/index.html`;
   - **C:** отказ от pure static export (не вписывается в ТЗ).

2. **`cacheComponents` + `partialPrefetching`** — текущий build использует PPR (`◐`). Для static export эти опции, скорее всего, нужно отключить/пересмотреть. **Проверка с `output:'export'` не запускалась.**

3. **Шрифт `next/font/google` (Manrope)** в `layout.tsx` — обычно совместим с export (встраивание при билде), но зависит от сети на CI при сборке.

### 3.2. Архитектурные

4. Все мутации завязаны на IndexedDB; нужен слой `apiClient` + тонкие repositories-адаптеры.
5. Импорт бэкапа сейчас **полностью заменяет** локальную БД транзакцией Dexie — на сервере нужна аналогичная транзакция MySQL + auth owner-only.
6. Один пользователь без публичной регистрации — в текущем коде auth **отсутствует** (ожидаемо).
7. `SPEC.md` пропал из дерева файлов — риск потери ТЗ в git; восстановить из истории/бэкапа до активной разработки.

### 3.3. Качество / CI

8. **`npm test` сейчас красный** (Vitest/`describe` config) — чинить до опоры на тесты в pipeline.
9. Нет GitHub Actions — CI/CD нужно создать с нуля.
10. Деплой FTP без SSH: риск затереть `api/` при заливке `out/` — нужны **раздельные remote-path** и exclude-правила.

### 3.4. Хостинг REG.RU

11. PHP + MySQL на shared/VPS REG.RU; конфиг БД **вне** `public_html`.
12. CORS/Same-origin: frontend и API лучше на одном origin (`crm.devisd.online` + `/api/...`) через размещение static в docroot и API в подкаталоге **или** subdomain с аккуратными cookie (`Secure`, `HttpOnly`, `SameSite`).
13. HTTPS и cookie-сессии обязательны для login.

---

## 4. Предлагаемая структура репозитория

Минимальные изменения относительно текущего monorepo:

```text
devisdCRM/
  src/                          # Next.js frontend (как сейчас)
  out/                          # результат next export (artifact, в .gitignore)
  api/                          # PHP API (новый)
    public/index.php            # front controller (единственная точка в public API)
    src/
      Config.php
      Db.php                    # PDO
      Auth/
      Controllers/
      Services/                 # money rules mirror
      Repositories/
      Middleware/
    migrations/
      001_init.sql
      002_....sql
    templates/                  # если понадобится
  config/                       # НЕ деплоить в public_html
    local.php.example
    # production: вне webroot на REG.RU
  .github/workflows/
    ci.yml
    deploy-frontend.yml
    deploy-api.yml
  docs/
    ONLINE_AUDIT_STAGE0_REG_RU.md   # этот отчёт
```

### 4.1. Frontend: переход IndexedDB → HTTP

1. Ввести `src/lib/api/http.ts` (fetch + credentials).
2. Реализовать `src/lib/repositories/*` как **адаптеры**:
   - `dataSource = 'local' | 'remote'` (env `NEXT_PUBLIC_DATA_SOURCE`)
   - remote вызывает PHP; local оставляет Dexie (не удалять на старте).
3. UI не трогать, кроме базового URL API и экрана login.
4. Для static export — решить маршруты `[id]` (п. 3.1) **до** включения `output: 'export'`.

### 4.2. PHP API (черновик эндпоинтов)

Соответствие текущим repository-функциям:

| Метод | Путь | Назначение |
|-------|------|------------|
| POST | `/api/auth/login` | owner login |
| POST | `/api/auth/logout` | logout |
| GET | `/api/auth/me` | сессия |
| GET/POST | `/api/clients` | list / create |
| GET/PATCH | `/api/clients/{id}` | get / update |
| POST | `/api/clients/{id}/archive` | archive/unarchive |
| GET/POST | `/api/orders` | list / create (+items, txn) |
| GET/PATCH | `/api/orders/{id}` | get / update (+items, txn) |
| POST | `/api/orders/{id}/status` | status |
| POST | `/api/orders/{id}/archive` | archive |
| POST | `/api/orders/{id}/payments` | add payment (txn, no overpay) |
| GET | `/api/overview` | KPI |
| GET | `/api/backup/export` | JSON v1 |
| POST | `/api/backup/import` | replace-all txn + validate |
| POST | `/api/backup/clear` | clear (confirm server-side) |

Требования:

- PDO prepared statements
- CSRF-токен или double-submit для мутаций + SameSite cookie
- Валидация на сервере (зеркалировать Zod-правила)
- Деньги: `BIGINT` копейки
- Транзакции на create/update order и payment

### 4.3. MySQL 8 (миграции)

Черновик `001_init.sql` (имена полей ≈ текущей модели):

- `users` (один owner: email, password_hash, created_at) — без публичной регистрации
- `clients`
- `orders` (`number` UNIQUE, `client_id` FK)
- `order_items`
- `payments`
- `app_meta` (`next_order_number`)
- `sessions` (если session store в БД)

Версионирование: папка `api/migrations/*.sql` + таблица `schema_migrations`.

---

## 5. План реализации небольшими этапами

| Этап | Содержание | Критерий готовности |
|------|------------|---------------------|
| **0** | Аудит (этот документ) | Согласован план |
| **1** | Починить `npm test`; восстановить `SPEC.md` в git | test/lint/typecheck зелёные |
| **2** | Spike: `output:'export'` + решение по `[id]` маршрутам | `next build` с export успешен; маршруты открываются на static hosting |
| **3** | Скелет PHP API + PDO + migration `001` + healthcheck | API отвечает на staging/local PHP |
| **4** | Auth owner-only (login/logout/me, secure cookie) | Без сессии CRUD 401 |
| **5** | Clients API + remote repository adapter | Список/CRUD клиента через API |
| **6** | Orders + items API (txn) | Создание заказа с позициями |
| **7** | Payments API | Частичная оплата, запрет переплаты |
| **8** | Overview + backup export/import на сервере | JSON v1 совместим |
| **9** | Отключить local по умолчанию; импорт из IndexedDB-бэкапа один раз | Данные на MySQL |
| **10** | GitHub Actions CI + раздельный FTP deploy frontend/api | Деплой на `crm.devisd.online` без затирания API |

Каждый этап: typecheck/lint/test (frontend) + ручной чеклист API + не удалять Dexie до этапа 9.

---

## 6. CI/CD (GitHub Actions + FTP/SFTP)

### 6.1. `ci.yml` (на каждый PR/push)

- `npm ci`
- `npm run typecheck`
- `npm run lint`
- `npm test`
- (с этапа 2) `npm run build` с `output: 'export'`

### 6.2. `deploy-frontend.yml` (после CI на `main`)

- Build static → `out/`
- Upload **только** содержимое `out/` в `public_html/` (или `public_html/app/`)
- **Exclude:** `api/**`, `config/**`, `.env`, `*.php` вне разрешённого

### 6.3. `deploy-api.yml`

- Upload `api/` в отдельный path, напр. `public_html/api/` (public только front controller)
- Config/credentials — **вне** webroot (ручная установка на REG.RU один раз; не в git)

Инструменты: `SamKirkland/FTP-Deploy-Action` или SFTP-action; два job с разными `server-dir`.

---

## 7. Секреты и параметры хостинга (без паролей)

### GitHub Secrets (имена)

| Secret | Назначение |
|--------|------------|
| `FTP_HOST` | Хост REG.RU |
| `FTP_USER` | Пользователь FTP/SFTP |
| `FTP_PASSWORD` | Пароль / ключ (если поддерживается) |
| `FTP_SERVER_DIR_FRONTEND` | Каталог выкладки static (напр. `/www/crm.devisd.online/`) |
| `FTP_SERVER_DIR_API` | Каталог API (напр. `/www/crm.devisd.online/api/`) |
| `FTP_PORT` | 21 или 22 |
| `NEXT_PUBLIC_API_BASE_URL` | `https://crm.devisd.online/api` (для build) |

Опционально: `FTP_SSH_KEY` если SFTP по ключу.

### Параметры на REG.RU (ручные, не в git)

| Параметр | Пример / заметка |
|----------|------------------|
| Домен | `crm.devisd.online` + SSL |
| MySQL host | из панели (часто `localhost`) |
| MySQL database / user | отдельные для CRM |
| PHP version | 8.1+ желательно |
| Путь к config | вне `public_html`, напр. `~/secure/crm-config.php` |
| Owner email | единственный пользователь |
| Password hash | создать локально (`password_hash`), не хранить plaintext |

---

## 8. Проверки по этапам (сводка)

| Этап | Автоматические | Ручные |
|------|----------------|--------|
| 1 | typecheck, lint, test | — |
| 2 | build export | открыть static preview, deep-link клиента/заказа |
| 3–4 | smoke PHP | login/logout, cookie Secure |
| 5–7 | API tests / Postman | CRUD + деньги + переплата отклонена |
| 8 | backup validate | import не портит БД при bad JSON |
| 9 | — | миграция реального JSON из локального MVP |
| 10 | Actions green | frontend и api залиты раздельно; API жив после frontend deploy |

---

## 9. Рекомендуемый следующий этап (не начинать без подтверждения)

**Этап 1 — стабилизация репозитория перед online-кодом:**

1. Выяснить и починить падение Vitest (`describe` / `config`).
2. Восстановить `SPEC.md` в репозитории (из git history / бэкапа).
3. Зафиксировать в коротком ADR выбор стратегии маршрутов для static export (query vs rewrite).
4. Повторно прогнать `typecheck`, `lint`, `test`, `build`.

**Не входит в этап 1:** PHP API, MySQL, удаление Dexie, деплой на production, изменение схемы данных в коде.

---

## 10. Явные пробелы аудита

- `output: 'export'` **не прогонялся** — совместимость не доказана билдом.
- REG.RU панель / реальные пути FTP **не проверялись** из этой среды.
- PHP/MySQL на сервере **не поднимались**.
- Падение тестов **зафиксировано, но не отлажено** на этапе 0.

Конец отчёта этапа 0.
