# Этап 2. Static Export — отчёт

**Ветка:** `feature/online-migration`
**Дата:** 2026-10-25
**Деплой / PHP / MySQL / бизнес-логика денег / Dexie:** не затрагивались.

---

## 1. Изменённые файлы и зачем

| Файл | Зачем |
|------|--------|
| `next.config.ts` | `output: 'export'`, `trailingSlash: true`; сняты `cacheComponents` / `partialPrefetching` (PPR запрещён в export) |
| `src/lib/routes.ts` | Единые URL с query `id` и trailing slash |
| `src/components/routing/RequireQueryId.tsx` | Чтение `?id=` + empty state при отсутствии |
| `src/app/clients/detail/*`, `edit/*` | Новые статические страницы |
| `src/app/orders/detail/*`, `edit/*` | Новые статические страницы |
| `src/app/clients/[id]/**`, `orders/[id]/**` | Удалены (динамические сегменты) |
| Компоненты lists/detail/forms/overview + `navItems.ts` | Все ссылки/push через `routes.*` |
| `docs/ADR-001-static-export-routes.md` | Статус → принято |

## 2. Маршруты

| Было | Стало |
|------|--------|
| `/clients/[id]` | `/clients/detail/?id=<uuid>` |
| `/clients/[id]/edit` | `/clients/edit/?id=<uuid>` |
| `/orders/[id]` | `/orders/detail/?id=<uuid>` |
| `/orders/[id]/edit` | `/orders/edit/?id=<uuid>` |

Без id → EmptyState с ссылкой на список. Suspense вокруг `useSearchParams`.

## 3. Результаты команд

| Команда | Результат |
|---------|-----------|
| `npm run typecheck` | OK (после очистки устаревшего `.next` с ссылками на `[id]`) |
| `npm run lint` | OK |
| `npm test` | OK — 9 files, 39 tests |
| `npm run build` | OK — все маршруты `○ Static` |

Промежуточная ошибка билда: `Invariant: PPR cannot be enabled in export mode` при `cacheComponents: true` — флаги отключены по этой причине.

## 4. Структура `out/` (~1.8 MB)

HTML (trailingSlash → `…/index.html`):

- `out/index.html`
- `out/clients/index.html`, `…/new/`, `…/detail/`, `…/edit/`
- `out/orders/index.html`, `…/new/`, `…/detail/`, `…/edit/`
- `out/settings/index.html`
- `out/404.html`, `out/404/index.html`, `out/_not-found/index.html`

Ресурсы: `out/_next/static/chunks/*.{js,css}`, `out/_next/static/media/*.{woff2,ico}`, `out/favicon.ico`, SVG из `public/`.

Ссылки в HTML — абсолютные от корня (`/_next/static/...`, `/clients/`, `/orders/`).

## 5. Ограничения static export

- Нет Node-сервера Next: только статика.
- Нет PPR / `cacheComponents` / `partialPrefetching`.
- Id только в query; старые path-URL с UUID больше не существуют.
- Данные по-прежнему в IndexedDB браузера (пока нет API).
- Asset paths от корня домена — ок для `https://crm.devisd.online`, не для подкаталога без `basePath`.

## 6. Что проверить на реальном REG.RU (не делалось)

- FTP `out/` в document root, открытие `/`, `/clients/`, `/clients/detail/?id=…`
- F5 / прямой deep-link на detail/edit
- CSS/JS/шрифты с `/_next/static/…`
- IndexedDB на HTTPS-origin
- 404 для несуществующих путей
- Совместимость с REG.RU **не доказана** одной локальной сборкой

## 7. Подтверждение границ этапа

PHP API, MySQL, CI/CD, деплой, авторизация, расчёты денег/оплат, формат JSON-бэкапа, удаление Dexie — **не выполнялись**.

## Следующий этап (не начат)

Этап 3 по плану миграции: скелет PHP API + PDO + migration `001` + healthcheck — только после подтверждения.
